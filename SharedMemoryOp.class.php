<?php

require_once('logging/MmLogger.class.php');
require_once('Packing.class.php');

/**
 * @brief   Provides structure for reading and writing to shared memory segments
 *          using PHPs shmop_* functions.
 *
 * Two shared memory segments are opened or created if they don't already exist.
 * The first segment is for storing an index of offsets, which references the second
 * segment where the actual data is stored.
 *
 * The index segment contains a 12 byte header containing a version number,
 * next index offset (the offset at which a new index can be written to the index
 * segment) and next data offset (the offset at which a new data item can be
 * written to the data segment). These three values are unsigned 32 bit integers.
 *
 * Derived classes should implement the following functionality:
 *
 * - An index strucutre which creates a unique identifier for a data item to be
 *   stored, that items offset (in bytes) in the data segment, the type of data
 *   being stored and its length. For example, an index may has a structrue:
 *     name (char)
 *     type (char)
 *     length (uint16)
 *     offset (uint32)
 *
 *   Resulting in an index that looks like:
 *     'a C 1 0' (the type C referrs to an unsigned char, see: http://php.net/manual/en/function.pack.php)
 *   which says that data item 'a' is a char stored at offset 0 in the data segment.
 *   The value for item 'a' can then be read and written using shmop_read and shmop_write
 *   using the length and offset values.
 * - A method for searching through the index to find the offset and length for
 *   a value based on its unique identifer. Using the above index as an example,
 *   looking up 'a' would return an offset of 0 and a length of 1, and optionally
 *   store the offset value somewhere to save further lookups.
 * - A method for adding a value to the index.
 * - Implementation of the __get and __set functions which use shmop_read and
 *   shmop_write on the data segment ($shmDataId), using the offset and length
 *   values of the value being read or written. A possible implementation of the
 *   __get function:
 *     lookup the offset and length of the value
 *     return data of the values length at the values offset in the data segment
 *     or false if the value doesn't exist in the index
 *   A possible implementation of the __set function:
 *     lookup the offset and length of the value
 *       if the value doesn't exist add it to the index
 *     write data of the values length at the values offset in the data segment
 *
 * Shared memory segments require a System V ID, which is created using ftok(),
 * using a temporary file stored in IPC_KEY_FILE_PATH named, using the $name and
 * $identifier passed to the constructor.
 *
 * This class extends stdClass. By overriding the __get and __set functions, any
 * operations on properties that aren't explicilty defined as properties of this
 * class (or any derived classes), will result in invokation of the __get and
 * possibly __set functions being called (depending on the operation). This allows
 * data to be read and written from shared memory using the following notation:
 * @code
 * $object->foo; // (invokes __get('foo'))
 * $object->foo = 'baa'; // (invokes __set('foo', 'baa'))
 * $object->counter++; // (invokes __get('counter') followed by __set('foo', n))
 *                     // where n is the return of the __get + 1.
 * @endcode
 *
 * This class uses file locking (flock) on the IPC key file to ensure data
 * consistency. File locking is performed in a non blocking fashion to prevent
 * deadlocks, with a lock wait timeout of 100 milliseconds. If a lock cannot be
 * obtained within the timeout the operation is aborted.
 *
 * @todo    Change the IPC_KEY_FILE_PATH constant to a variable and allow for
 *          derived classes to set this value.
 * @todo    Change the LOCK_WAIT_TIME constant to a variable and allow for
 *          derived classes to set this value.
 *
 * @see     metrics/MetricsLogger.class.php, an implementation of SharedMemoryOp.
 * @see     Packing.class.php, for helper functions and constants when working
 *          with PHP's pack and unpack functions.
 * @see     http://php.net/manual/en/function.ftok.php
 * @see     http://www.php.net/manual/en/book.shmop.php
 * @see     http://www.php.net/manual/en/function.pack.php
 * @see     http://www.php.net/manual/en/function.unpack.php
 */
abstract class SharedMemoryOp extends stdClass {

    const MODE_READ_ONLY  = 'r';                     ///< Open shared memory segments for reading only.
    const MODE_READ_WRITE = 'w';                     ///< Open shared memory segments for reading and writing.

    const IPC_KEY_FILE_PATH          = '/var/tmp/';  ///< Path to use when creating System V IPC key.
                                                     ///  Must match the corresponding PMDA implementation.
    const PAGE_SIZE = 4096;                          ///< Page size used on most MessageMedia production servers.
    const LOCK_WAIT_TIMEOUT = 100;                   ///< Default time in milliseconds to wait before failing to obtain a lock.

    const FTOK_PROJECT_INDEX_IDENTIFIER = 'i';       ///< ftok project identifier for index segment.
    const FTOK_PROJECT_DATA_IDENTIFIER = 'd';        ///< ftok project identifier for data segment.
    const SHARED_MEMORY_MODE = 0644;                 ///< Access mode to use when creating shared memory segments.

    protected $version    = 1;                       ///< Version of the data structure being stored.
    protected $name       = '';                      ///< Name for the shared memory segment.
    protected $identifier = '';                      ///< A secondary identifier to be used with the $name.

    protected $shmIndexId      = false;              ///< Shared memory ID for the index returned by shmop_open().
    protected $shmDataId       = false;              ///< Shared memory ID for the data returned by shmop_open().
    protected $shmIndexPages   = 1;                  ///< Number of pages to reserve for index shared memory segment on creation.
    protected $shmDataPages    = 1;                  ///< Number of pages to reserve for data shared memory segment on creation.
    protected $ipcKeyFile      = false;              ///< Path to file used when creating System V IPC Key.
    protected $hasError        = false;              ///< Flag any permanent errors.
    protected $readOnly        = false;              ///< Open shared memory segments as read only.
    protected $developmentMode = false;              ///< Flag to enable development mode, which turns on metric validation, and duplicate metric warnings.

    protected $versionStructure = array( ///< Structure of the version stored in the head of the index segment.
                                         ///  @see http://php.net/manual/en/function.pack.php.
        'version' => Packing::UINT32,    ///< Version stored as unsigned long.
    );

    protected $offsetsStructure = array(      ///< Structure of the head stored in the index segment.
                                              ///  @see http://php.net/manual/en/function.pack.php.
        'nextIndexOffset' => Packing::UINT32, ///< Next index offset stored as unsigned long.
        'nextDataOffset'  => Packing::UINT32, ///< Next data offset stored as unsigned long.
    );

    /**
     * @brief   Construct a SharedMemoryOp object.
     *
     * Attaches and initializes shared memory segments for the index and data.
     *
     * @param   $name           Name given to this object, typically an identifier
     *                          for the data being stored, i.e. soapxml, email2sms, db.
     * @param   $identifier     A secondary identifier to be used with the $name.
     * @param   $version        The version of the data structure being stored.
     * @param   $mode           Mode to open shared memory segments in, defaults to read and write.
     */
    public function __construct($name, $identifier, $version, $mode = self::MODE_READ_WRITE) {
        $this->name       = $name;
        $this->version    = $version;
        $this->identifier = $identifier;
        $this->ipcKeyFile = self::IPC_KEY_FILE_PATH . $name . '.' . $identifier;
        if ($mode == self::MODE_READ_ONLY) {
            $this->readOnly = true;
        }

        if (!$this->initializeSharedMemorySegment()) {
            $this->hasError = true;
            MmLogger::log('Failed to initialize shared memory segment ' . $this->ipcKeyFile, basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
        }
    }

    /**
     * @brief   Derived classes must implement a getter function.
     */
    public abstract function __get($name);

    /**
     * @brief   Derived classes must implement a setter function.
     */
    public abstract function __set($name, $value);

    /**
     * @brief   Initializes a shared memory segment.
     *
     * Creates an System V IPC Key file in /var/tmp if it doesn't already exist,
     * attempts to obtain an exclusive lock on that file, opens the index shared
     * memory segment and the data shared memory segment, sets or upgrades the
     * version and sets the index offset and the data offset if not already set.
     *
     * @return  True on successful initialization, false otherwise.
     */
    protected function initializeSharedMemorySegment() {
        if (!extension_loaded('shmop')) {
            $this->hasError = true;
            MmLogger::log('shmop does not exist', basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
            return false;
        }

        if (!file_exists($this->ipcKeyFile)) {
            if (!$this->readOnly) {
                try {
                    touch($this->ipcKeyFile);
                } catch (Exception $e) {
                    $this->hasError = true;
                    MmLogger::log('Failed to touch ' . $this->ipcKeyFile, basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
                    return false;
                }
            } else {
                $this->hasError = true;
                MmLogger::log('File does not exist ' . $this->ipcKeyFile, basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
                return false;
            }
        }

        $ipcKeyFileHandle = fopen($this->ipcKeyFile, 'r');
        if ($ipcKeyFileHandle !== false) {
            try {
                $this->shmIndexId = $this->openOrCreate(self::FTOK_PROJECT_INDEX_IDENTIFIER, $this->shmIndexPages);
                $this->shmDataId  = $this->openOrCreate(self::FTOK_PROJECT_DATA_IDENTIFIER,  $this->shmDataPages);
                if ($this->shmIndexId !== false && $this->shmDataId !== false) {
                    $head = $this->getHead();
                    if (!$this->readOnly && ($head['version'] == 0 || $head['version'] < $this->version)) {
                        if ($this->getLock($ipcKeyFileHandle, LOCK_EX)) {
                            $head = $this->getHead();
                            if ($head['version'] == 0) {
                                // Set the version if not already set.
                                $data = pack(Packing::getPackFormat('head', $this->getHeadStructure()), $this->version, Packing::getPackLength($this->getHeadStructure()), 0);
                            } else if ($head['version'] < $this->version) {
                                // Upgrade the version if an older version exists.
                                $data = pack($this->versionStructure['version'], $this->version);
                            }
                            shmop_write($this->shmIndexId, $data, 0);
                            flock($ipcKeyFileHandle, LOCK_UN);
                            fclose($ipcKeyFileHandle);
                            return true;
                        } else {
                            MmLogger::log('Failed to obtain a lock on ' . $this->ipcKeyFile, basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
                        }
                    } else {
                        return true;
                    }
                }
            } catch (Exception $e) {
                // Let any exceptions fall through, and close the file
                MmLogger::log($e->getMessage(), basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
            }
        } else {
            MmLogger::log('Failed to open ' . $this->ipcKeyFile, basename(__FILE__), __LINE__, __FUNCTION__, LOG_ERR);
        }
        if ($this->shmIndexId !== false) {
            shmop_close($this->shmIndexId);
        }
        if ($this->shmDataId !== false) {
            shmop_close($this->shmDataId);
        }
        flock($ipcKeyFileHandle, LOCK_UN);
        fclose($ipcKeyFileHandle);
        return false;
    }

    /**
     * @brief   Opens or creates a shared memory segment.
     *
     * Attempts to open a shared memory segment for the specifed project identifier.
     * If the segment cannot be opened, attempts to create the shared memory segment.
     *
     * @param   $projectIdentifier  Project identifier to use in ftok call.
     * @param   $pages              Size of shared memory segment if being created.
     *
     * @return  An shared memory segment ID on success, false otherwise.
     */
    protected function openOrCreate($projectIdentifier, $pages) {
        $key = ftok($this->ipcKeyFile, $projectIdentifier);
        if (is_long($key) && $key != -1) {
            $mode = ($this->readOnly) ? 'a' : 'w';
            @$shmId = shmop_open($key, $mode, 0, 0);
            if ($shmId !== false) {
                if ($this->developmentMode) {
                    MmLogger::log('Opened shared memory segment 0x' . dechex($key), basename(__FILE__), __LINE__, __FUNCTION__, LOG_INFO);
                }
                return $shmId;
            } else {
                if (!$this->readOnly) {
                    $shmId = shmop_open($key, 'c', self::SHARED_MEMORY_MODE, $pages * self::PAGE_SIZE);
                    if ($shmId !== false) {
                        MmLogger::log('Created and opened shared memory segment 0x' . dechex($key), basename(__FILE__), __LINE__, __FUNCTION__, LOG_INFO);
                        return $shmId;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @brief   Helper function for getting the head data (version, nextIndexOffset,
     *          nextDataOffset) from the index shared memory segment.
     *
     * @return  Associative array containing version, nextIndexOffset and nextDataOffset
     *          from the index shared memory segment.
     */
    protected function getHead() {
        return unpack(Packing::getPackFormat('head', $this->getHeadStructure(), true), shmop_read($this->shmIndexId, 0, Packing::getPackLength($this->getHeadStructure())));
    }

    /**
     * @brief   Returns the head structure of the index
     */
    protected function getHeadStructure() {
        return array_merge($this->versionStructure, $this->offsetsStructure);
    }

    /**
     * @brief   Deletes the shared memory segements.
     */
    public function deleteSharedMemory($deleteKeyFile = true) {
        if ($this->shmIndexId !== false) {
            shmop_delete($this->shmIndexId);
            shmop_close( $this->shmIndexId);
            $this->shmIndexId = false;
        }
        if ($this->shmDataId !== false) {
            shmop_delete($this->shmDataId);
            shmop_close( $this->shmDataId);
            $this->shmDataId = false;
        }
        if (file_exists($this->ipcKeyFile) && $deleteKeyFile) {
            unlink($this->ipcKeyFile);
        }
    }

    /**
     * @brief   Return the value of hasError.
     *
     * @return  True if hasError is true, false otherwise.
     */
    public function isError() {
        return $this->hasError;
    }

    /**
     * @brief   Getter for the name property.
     *
     * @return  $name property.
     */
    public function getName() {
        return $this->name;
    }

    /**
     * @brief   Getter for the identifier property.
     *
     * @return  $identifier property.
     */
    public function getidentifier() {
        return $this->identifier;
    }

    /**
     * @brief   Getter for the ipcKeyFile property.
     *
     * @return  $ipcKeyFile property.
     */
    public function getIpcKeyFile() {
        return $this->ipcKeyFile;
    }

    /**
     * @brief   Attempts to get a lock on the file handle $fp within the specified
     *          timeout, specified by $timeout.
     *
     * @param   $fp         File handle to obtain lock on.
     * @param   $lock       Type of lock exclusive or shared (LOCK_EX or LOCK_SH).
     * @param   $timeout    Time to wait before giving up on lock in milliseconds.
     *
     * @return  True if lock obtained, false otherwise
     *
     * @todo    Find a better location for this, a FileUtils class or something similar.
     */
    protected function getLock(&$fp, $lock, $timeout = self::LOCK_WAIT_TIMEOUT) {
        // Validate lock type
        if ($lock != LOCK_EX && $lock != LOCK_SH) {
            return false;
        }
        $startTime = microtime(true);
        do {
            $canWrite = flock($fp, $lock | LOCK_NB);
            // If lock not obtained sleep for 0 - 10 milliseconds, to avoid collision and CPU load
            if (!$canWrite) {
                $sleep = round(rand(0, 10) * 1000);
                usleep($sleep);
            }
        } while ((!$canWrite) && ((microtime(true) - $startTime) < ($timeout / 1000)));
        return $canWrite;
    }
}
