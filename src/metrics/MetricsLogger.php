<?php
/**
 * Copyright 2013 MessageMedia Group
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @file
 * @copyright 2013 MessageMedia Group
 * @license https://www.apache.org/licenses/LICENSE-2.0
 * @see https://messagemedia.github.io/
 */

namespace MessageMedia\shmop\metrics;

use MessageMedia\shmop\Packing;
use MessageMedia\shmop\SharedMemoryOp;

/**
 * @brief  Provides a structure for implementing counter and timer style application
 *         level metrics within PHP, using shared memory for storage.
 *
 * This class serves two purposes.
 *
 * Firstly is implements functionality required by the SharedMemoryOp class of
 * which it extends, including an index structure, a method for finding values
 * in the index, a method for adding values to the index and __get and __set
 * functions which use shmop_read and shmop_write. The index structure is designed
 * to allow PMDAs written for PCP to map metrics to metrics defined within the PMDA.
 * This is done by storing a cluster, item and instance value in the index, which
 * are the three identifiers PCP uses to index metrics.
 *
 * Secondly, it provides a structure for counting and timing metrics. Counter metrics
 * are simple monotonic counts of something (requests, bytes, etc...), whilst
 * timing metrics record how long something took (service_time), a histogram of
 * timings recorded within specific ranges and a count of the number of timings
 * have been recorded. When a counter metric is specificed, a single metric is created
 * to capture this metric, when a timing metric is specified, eight metrics are
 * created.
 *
 * Derived classes need to provide a list of metrics to capture with meta data for
 * each metric including:
 *   - type: counter or timer
 *   - name: name for the metric
 *   - pcp_cluster: a cluster ID which matches the cluster ID given to the metric
 *                  in the consuming PMDA
 *   - pcp_item: an item ID which matches the item ID given to the metric
 *               in the consuming PMDA (optional for timer metrics)
 *   - pcp_instance: an instance ID which matches the instance ID given to the metric
 *                   in the consuming PMDA, if a metric doesn't belong to an instance
 *                   use -1, provided by the constant INSTANCE_DOMAIN_NULL.
 *
 * Metrics are provided as an array of associative arrays and are validated upon
 * construction - in development mode only - according to the following rules:
 *   - Metrics must have a valid type, COUNTER or TIMER
 *   - Metrics must have a unique valid name
 *   - Metrics must have a valid pcp_cluster value between 0 and 65535
 *   - Metrics that aren't TIMERs must have a valid pcp_item value between 0 and 65535
 *   - TIMER metrics without a pcp_item or pcp_start_item value default to pcp_item 0
 *   - Metrics may have a pcp_instance value between -2147483648 and 2147483647,
 *     if no pcp_instance value is provided, -1 is used as a default.
 *   - The combination of a metrics pcp_cluster, pcp_item and pcp_instance values
 *     must be unique within a derived class. Metrics with duplicate values are
 *     ignored.
 *
 * Metric values are validated during assignment operations, and will be set to 0 if
 * invalid values are detected. COUNTER and TIMER metric values must be:
 *   - Positive integer values
 *   - Must not exceed the maximum value for a 32 bit integer value in PHP which
 *     is typically 2147483647 on a 32 bit OS and 4294967295 on a 64 bit OS.
 *
 * Example 1 - Defining and incrementing a counter called things.
 * @code
 * $metrics = array(
 *     array(
 *         'type'         => MetricsLogger::METRIC_TYPE_COUNTER,
 *         'name'         => 'things',
 *         'pcp_cluster'  => 0,
 *         'pcp_item'     => 0,
 *         'pcp_instance' => MetricsLogger::INSTANCE_DOMAIN_NULL,
 *     )
 * );
 * $object = new DerivedMetricsLogger($metrics);
 * echo $object->things; // Prints 0
 *
 * $object->things = 10; // Set the value of things to 10
 * echo $object->things; // Prints 10
 *
 * $object->things++;    // Increment the value of things by 1
 * echo $object->things; // Prints 11
 * @endcode
 *
 * Example 2 - Defining and recording timings in a timer called time.
 * @code
 * $metrics = array(
 *     array(
 *         'type'        => MetricsLogger::METRIC_TYPE_TIMER,
 *         'name'        => 'time',
 *         'pcp_cluster' => 0,
 *         'pcp_item'    => 0,
 *         'pcp_instance' => MetricsLogger::INSTANCE_DOMAIN_NULL,
 *     )
 * );
 * $object = new DerivedMetricsLogger($metrics);
 * // On construction, 8 metrics have been created.
 * echo $object->time.service_time; // Prints 0
 * echo $object->time.time_taken_0; // Prints 0
 * echo $object->time.time_taken_1; // Prints 0
 * echo $object->time.time_taken_2; // Prints 0
 * echo $object->time.time_taken_3; // Prints 0
 * echo $object->time.time_taken_4; // Prints 0
 * echo $object->time.time_taken_5; // Prints 0
 * echo $object->time.timings_count; // Prints 0
 *
 * $object->timing('time', 2000); // Record that a 'time' event took 2 seconds (2000 milliseconds)
 * echo $object->time.service_time; // Prints 2000, service time is an aggregate count of all timings recorded
 * echo $object->time.time_taken_0; // Prints 0
 * echo $object->time.time_taken_1; // Prints 1, 2 seconds falls into this histogram bin
 * echo $object->time.time_taken_2; // Prints 0
 * echo $object->time.time_taken_3; // Prints 0
 * echo $object->time.time_taken_4; // Prints 0
 * echo $object->time.time_taken_5; // Prints 0
 * echo $object->time.timings_count; // Prints 1
 *
 * $object->timing('time', 15000); // Record that a 'time' event took 15 seconds (15000 milliseconds)
 * echo $object->time.service_time; // Prints 17000, service time is an aggregate count of all timings recorded
 * echo $object->time.time_taken_0; // Prints 0
 * echo $object->time.time_taken_1; // Prints 1
 * echo $object->time.time_taken_2; // Prints 0
 * echo $object->time.time_taken_3; // Prints 1, 15 seconds falls into this histogram bin
 * echo $object->time.time_taken_4; // Prints 0
 * echo $object->time.time_taken_5; // Prints 0
 * echo $object->time.timings_count; // Prints 2
 * @endcode
 *
 * Example 3 - Defining duplicate metrics.
 * @code
 * $metrics = array(
 *     array(
 *         'type'         => MetricsLogger::METRIC_TYPE_COUNTER,
 *         'name'         => 'first',
 *         'pcp_cluster'  => 0,
 *         'pcp_item'     => 0,
 *         'pcp_instance' => 0,
 *     ),
 *     array(
 *         'type'         => MetricsLogger::METRIC_TYPE_COUNTER,
 *         'name'         => 'second',
 *         'pcp_cluster'  => 0,
 *         'pcp_item'     => 0,
 *         'pcp_instance' => 0,
 *     ),
 * );
 * $object = new DerivedMetricsLogger($metrics);
 * echo $object->first;  // Prints 0
 * echo $object->second; // Prints nothing, value is false.
 * @endcode
 *
 * @see     api/xml/SoapXmlMetricsLogger.class.php, an implementation of MetricsLogger.
 * @see     http://oss.sgi.com/projects/pcp/
 */
abstract class MetricsLogger extends SharedMemoryOp
{

    const INSTANCE_DOMAIN_NULL = -1; ///< Value to use as the pcp_instance when a metric has no instance domain.

    const METRIC_TYPE_COUNTER = 'counter'; ///< monotonic counter.
    const METRIC_TYPE_TIMER   = 'timer';   ///< interval timer.

    ///< Array of metrics to be captured by this class.
    protected $metrics = array();

    ///< Array of value names and with their offset and pack type. Can also include flags and length of each value.
    protected $indexData = array();

    ///< Flag to enable development mode, which turns on metric validation, and duplicate metric warnings.
    protected $developmentMode = false;

    /// Array or timing metrics to collect.
    protected $timingMetrics = array(
        'service_time'  => Packing::UINT32, ///< Total time recorded.
        'time_taken_0'  => Packing::UINT32, ///< Number of recorded values < 1 second.
        'time_taken_1'  => Packing::UINT32, ///< Number of recorded values >= 1 second and < 5 seconds.
        'time_taken_2'  => Packing::UINT32, ///< Number of recorded values >= 5 seconds and < 10 seconds.
        'time_taken_3'  => Packing::UINT32, ///< Number of recorded values >= 10 seconds and < 20 seconds.
        'time_taken_4'  => Packing::UINT32, ///< Number of recorded values >= 20 seconds and < 5 seconds.
        'time_taken_5'  => Packing::UINT32, ///< Number of recorded values >= 40 seconds.
        'timings_count' => Packing::UINT32, ///< Count of recorded timing events.
    );

    /// Structure of the data info lists stored in the index segment.
    ///  @see http://php.net/manual/en/function.pack.php.
    protected $indexStructure = array(
        'flags'    => Packing::UCHAR,  ///< Flags (unused) stored as an unsigned char.
        'type'     => Packing::UCHAR,  ///< Type stored as unsigned char.
        'length'   => Packing::UINT16, ///< Length stored as unsigned short.
        'offset'   => Packing::UINT32, ///< Offset stored as unsigned long.
        'cluster'  => Packing::UINT16, ///< Cluster stored as unsigned short (to match PCP).
        'item'     => Packing::UINT16, ///< Item stored as unsigned short (to match PCP).
        'instance' => Packing::INT32,  ///< Instance stored as signed long.
    );

    /**
     * @brief   Construct a MetricsLogger object.
     *
     * Attaches and initializes shared memory segment, creates array of metrics
     * and keys and sets the version.
     *
     * @param   $name     Name given to this object, typically an identifier
     *                    for the metrics being collected, i.e. soapxml, email2sms, db.
     * @param   $metrics  Array of metrics defined for this metrics logger, which
     *                    correlate to the metrics defined in the PMDA reading
     *                    the data. @see http://linux.die.net/man/3/pmda.
     * @param   $version  The version of the data structure being stored in $keyIds.
     * @param   $mode     The mode in which this class should operate, read and write or read only.
     * @param   $developmentMode Flag to enable development mode.
     */
    public function __construct(
        $name,
        $metrics,
        $version,
        $mode = SharedMemoryOp::MODE_READ_WRITE,
        $developmentMode = false
    ) {
        $this->developmentMode = $developmentMode;
        $this->metrics         = $this->parseMetrics($metrics);

        $metricsCount = count($this->metrics) * 4; // Allowing for a 4-fold increase in amount of metrics.
        $this->shmIndexPages = ceil((12 + ($metricsCount * 16)) / self::PAGE_SIZE); // 12 bytes for head segment, and
                                                                                    // 16 bytes for each metric index.
        $this->shmDataPages  = ceil(($metricsCount * 4) / self::PAGE_SIZE); // 4 bytes for each metric.

        parent::__construct($name, 'metrics', $version, $mode);
    }

    /**
     * @brief   Creates a key to ID map of all metrics.
     *
     * The returned map will contain all metrics that this metrics logged can
     * keep track of. Map keys will be the name of the metric, map values will be
     * and associative array containting flags, type, pcp_cluster, pcp_item and
     * pcp_instance.
     *
     * If development mode is enabled, this function will validate all metrics
     * and warn on duplicate metrics.
     *
     * @param   $metrics    Array of metric configurations.
     *
     * @return  Associative array of metrics to IDs.
     */
    protected function parseMetrics($metrics)
    {
        $parsedMetrics = array();
        if ($this->developmentMode) {
            $pcpHashes = array(); ///< Used to check for duplicate PCP IDs.
        }
        foreach ($metrics as $index => $config) {
            if ($this->developmentMode) {
                $this->validateMetricConfig($index, $config, $pcpHashes);
            }
            if ($config['type'] == self::METRIC_TYPE_COUNTER) {
                $keyName = $config['name'];
                if ($this->developmentMode && isset($parsedMetrics[$keyName])) {
                    $this->logger->warning('Duplicate key name for ' . $keyName);
                }
                $parsedMetrics[$keyName] = array(
                    'flags'        => 0,
                    'type'         => Packing::UINT32,
                    'pcp_cluster'  => $config['pcp_cluster'],
                    'pcp_item'     => $config['pcp_item'],
                    'pcp_instance' => $config['pcp_instance'],
                );
            } elseif ($config['type'] == self::METRIC_TYPE_TIMER) {
                foreach ($this->timingMetrics as $metric => $type) {
                    $keyName = $config['name'] . '.' . $metric;
                    if ($this->developmentMode && isset($parsedMetrics[$keyName])) {
                        $this->logger->warning('Duplicate key name for ' . $keyName);
                    }
                    $parsedMetrics[$keyName] = array(
                        'flags'        => 0,
                        'type'         => $type,
                        'pcp_cluster'  => $config['pcp_cluster'],
                        'pcp_item'     => $config['pcp_item'],
                        'pcp_instance' => $config['pcp_instance'],
                    );
                    $config['pcp_item']++;
                }
            }
        }
        return $parsedMetrics;
    }

    /**
     * @brief   Validation configuration for metrics.
     *
     * Ensures metric configurations are valid. All metrics require a pcp_cluster
     * value, used when creating an ID comprising of the pcp_cluster, pcp_item
     * and pcp_instance.
     *
     * Counter metrics require a valid name.
     *
     * Non timer metrics require a pcp_item value, used when creating an ID
     * comprising of the pcp_cluster, pcp_item and pcp_instance.
     *
     * Metrics that don't have a pcp_item value will have this value initialized
     * to 0, or to the pcp_start_item.
     *
     * To minimise the performance impact this class has on code that uses it,
     * this function is only used in development mode.
     *
     * @param   $index      Index of the item, used for logging warnings.
     * @param   $config     Associative array of config values.
     * @param   $pcpHashes  Array of hash PCP metrics, used to identify duplicates,
     *                      passed by reference.
     *
     * @return  True if valid, false otherwise.
     */
    protected function validateMetricConfig($index, $config, &$pcpHashes)
    {
        // Metric must have a valid type
        if (!isset($config['type']) || !in_array($config['type'], array(
            self::METRIC_TYPE_COUNTER,
            self::METRIC_TYPE_TIMER,
        ))) {
            $this->logger->warning('Missing or invalid type property, ignoring metric at index ' . $index);
            return false;
        }

        // All metrics must have a name
        if (!isset($config['name']) || !is_string($config['name'])) {
            $this->logger->warning('Missing name property, ignoring metric at index ' . $index);
            return false;
        }

        // All metrics must have a pcp_cluster property
        if (!isset($config['pcp_cluster']) || !is_int($config['pcp_cluster'])) {
            $this->logger->warning('Missing or invalid pcp_cluster property, ignoring metric at index ' . $index);
            return false;
        }

        if ($config['type'] != self::METRIC_TYPE_TIMER) {
            // Only timers don't require a pcp_item property
            if (!isset($config['pcp_item']) || !is_int($config['pcp_item'])) {
                $this->logger->warning('Missing or invalid pcp_item property, ignoring metric at index ' . $index);
                return false;
            }
        }

        if ($config['pcp_cluster'] < 0 || $config['pcp_cluster'] > 65535) { // Max UINT16
            $this->logger->warning('Out of range pcp_cluster property, ignoring metric at index ' . $index);
            return false;
        }
        if ($config['pcp_item'] < 0 || $config['pcp_item'] > 65535) { // Max UINT16
            $this->logger->warning('Out of range pcp_item property, ignoring metric at index ' . $index);
            return false;
        }
        if ($config['pcp_instance'] < -2147483648 || $config['pcp_instance'] > 2147483647) { // Max INT32
            $this->logger->warning('Out of range pcp_instance property, ignoring metric at index ' . $index);
            return false;
        }

        $pcpHash = md5($config['pcp_cluster'] . $config['pcp_item'] . $config['pcp_instance']);
        if (in_array($pcpHash, $pcpHashes)) {
            $this->logger->warning('Duplicate PCP ID, ignoring metric at index ' . $index);
            return false;
        } else {
            $pcpHashes[] = $pcpHash;
        }
        return true;
    }

    /**
     * @brief   Wraps findValue() function with shared locking.
     *
     * @param   $name   Name of value to find in the index shared memory segment.
     *
     * @return  True if value found, false otherwise.
     */
    protected function findValueWithLock($name)
    {
        if (!isset($this->metrics[$name])) {
            $this->logger->warning('Attempted to find undefined metric ' . $name);
            return false;
        }

        $fp = fopen($this->ipcKeyFile, 'r');
        if ($fp !== false) {
            try {
                if ($this->getLock($fp, LOCK_SH)) {
                    $valueFound = $this->findValue($name);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return $valueFound;
                } else {
                    $this->logger->error('Failed to obtain a lock on ' . $this->ipcKeyFile);
                }
            } catch (Exception $e) {
                // Let any exceptions fall through, unlock and close the file
                $this->logger->error($e->getMessage());
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            $this->logger->error('Failed to open ' . $this->ipcKeyFile);
        }
        return false;
    }

    /**
     * @brief   Attempts to find a value in the index shared memory segment to
     *          get its offset in the data shared memory segment.
     *
     * This function should only be used if a lock has been obtained on the
     * IPC key file. Using findValueWith lock provides this functionality.
     *
     * @param   $name   Name of value to find in the index shared memory segment.
     *
     * @return  True if value found, false otherwise.
     */
    protected function findValue($name)
    {
        $cluster  = $this->metrics[$name]['pcp_cluster'];
        $item     = $this->metrics[$name]['pcp_item'];
        $instance = $this->metrics[$name]['pcp_instance'];
        $head = $this->getHead();

        $startIndexOffset = Packing::getPackLength($this->getHeadStructure()); // Indexes are after the head structure.
        $endIndexOffset = $head['nextIndexOffset']; // nextIndexOffset gives the tail, just past the final index item.
        $indexStructureLength = Packing::getPackLength($this->indexStructure);

        for ($indexOffset = $startIndexOffset; $indexOffset < $endIndexOffset; $indexOffset += $indexStructureLength) {
            $data = unpack(
                Packing::getPackFormat('index', $this->indexStructure, true),
                shmop_read($this->shmIndexId, $indexOffset, $indexStructureLength)
            );
            if (($data['cluster'] == $cluster) && ($data['item'] == $item) && ($data['instance'] == $instance)) {
                $this->indexData[$name]['offset'] = $data['offset'];
                return true;
            }
        }
        return false;
    }

    /**
     * @brief   Adds a new value to the shared memory segment.
     *
     * Adds a value to the shared memory segment index and this classes internal
     * index.
     *
     * @param   $name   Name of the value to add.
     *
     * @return  True if added, false otherwise.
     */
    protected function addNewValue($name)
    {
        if (!isset($this->metrics[$name])) {
            $this->logger->warning('Attempted to add undefined metric ' . $name);
            return false;
        }

        $fp = fopen($this->ipcKeyFile, 'r');
        if ($fp !== false) {
            try {
                if ($this->getLock($fp, LOCK_EX)) {
                    if ($this->findValue($name)) {
                        // The value may have been added between the last check
                        // and locking of the file.
                        return true;
                    }

                    $head = $this->getHead();
                    $indexStructureLength = Packing::getPackLength($this->indexStructure);

                    if (shmop_size($this->shmIndexId) < $head['nextIndexOffset'] + $indexStructureLength) {
                        $this->logger->error('Index shared memory segment full, cannot add ' . $name);
                        return false;
                    }

                    $newValueLength = Packing::getTypeLength($this->metrics[$name]['type']);
                    if (shmop_size($this->shmDataId) < $head['nextDataOffset'] + $newValueLength) {
                        $this->logger->error('Data shared memory segment full, cannot add ' . $name);
                        return false;
                    }

                    /// Zero the destination data.
                    shmop_write($this->shmDataId, pack("x{$newValueLength}"), $head['nextDataOffset']);

                    $cluster  = $this->metrics[$name]['pcp_cluster'];
                    $item     = $this->metrics[$name]['pcp_item'];
                    $instance = $this->metrics[$name]['pcp_instance'];
                    $type     = $this->metrics[$name]['type'];

                    $flags = 0; // Currently unused

                    // Write the new item into the index.
                    $data = pack(
                        Packing::getPackFormat('index', $this->indexStructure),
                        $flags,     ///< Order of parameters must match order in $this->indexStrucutre
                        ord($type), ///< @todo Find a way to pass these in the same order as $this->indexStrucutre
                        $newValueLength,
                        $head['nextDataOffset'],
                        $cluster,
                        $item,
                        $instance
                    );
                    if (strlen($data) != $indexStructureLength) {
                        $this->logger->error(
                            'Incorrect index length ' . strlen($data) . ', length should be ' . $indexStructureLength
                        );
                        return false;
                    }
                    $bytesWritten = shmop_write($this->shmIndexId, $data, $head['nextIndexOffset']);
                    if ($bytesWritten != $indexStructureLength) {
                        $this->logger->error(
                            'Incorrect index bytes written ' . $bytesWritten . ', bytes written should be ' .
                            $indexStructureLength
                        );
                        return false;
                    }

                    // Store data about this value locally.
                    $this->indexData[$name]['offset'] = $head['nextDataOffset'];
                    $this->indexData[$name]['length'] = $newValueLength;
                    $this->indexData[$name]['type']   = $type;

                    // Write the new offsets to the index segment.
                    $head['nextIndexOffset'] += $bytesWritten;
                    $head['nextDataOffset'] += $newValueLength;
                    $packedOffsetData = pack(
                        Packing::getPackFormat('offset', $this->offsetsStructure),
                        $head['nextIndexOffset'],
                        $head['nextDataOffset']
                    );
                    $packedOffsetDataBytesWritten = shmop_write(
                        $this->shmIndexId,
                        $packedOffsetData,
                        Packing::getPackLength($this->versionStructure)
                    );
                    if ($packedOffsetDataBytesWritten == Packing::getPackLength($this->offsetsStructure)) {
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        return true;
                    }
                } else {
                    $this->logger->error('Failed to obtain a lock on ' . $this->ipcKeyFile);
                }
            } catch (Exception $e) {
                // Let any exceptions fall through, unlock and close the file
                $this->logger->error($e->getMessage());
            }
            flock($fp, LOCK_UN);
            fclose($fp);
        } else {
            $this->logger->error('Failed to open ' . $this->ipcKeyFile);
        }
        return false;
    }

    /**
     * @brief   Validates a value against its type and modifies
     *          if necessary.
     *
     * @param   $name     Name of the value to validate.
     * @param   $value    The value to validate.
     */
    protected function validateValue(&$value, $name)
    {
        // All metrics should be numeric.
        if (is_numeric($value)) {
            // Ensure integers don't exceed their limit and convert to doubles.
            $maxUint32Value = min(PHP_INT_MAX, 4294967295); // The former applies on 32-bit systems.
            if ($this->metrics[$name]['type'] == Packing::UINT32 && ($value >= $maxUint32Value || !is_int($value))) {
                $this->logger->notice('Wrapping value for ' . $name);
                $value = 0;
            }
            // Ensure all metrics are positive numbers.
            if ($value < 0) {
                $this->logger->warning('Ignoring / resetting negative value ' . $value . ' for ' . $name);
                $value = 0;
            }
        } else {
            $this->logger->warning('Ignoring / resetting non numeric value ' . print_r($value, true) . ' for ' . $name);
            $value = 0;
        }
    }

    /**
     * @brief   Override of the __get function.
     *
     * Reads a value from the shared memory segment initialized by this class.
     * When getting a value whose name doesn't exist in the keyIds array, false
     * is returned. False is also returned if the shared memory segment cannot
     * be accessed or read from.
     *
     * @param   $name     Name of the value to return.
     *
     * @return  Value of that property if it's name exists in the classes $metrics
     *          array, false if it doens't exist or the shared memory segment
     *          cannot be accessed or read from.
     */
    public function __get($name)
    {
        if ($this->hasError) {
            return false;
        }
        if ($this->shmIndexId === false || $this->shmDataId === false) {
            if (!$this->initializeSharedMemorySegment()) {
                $this->hasError = true;
            }
        }
        if ($this->hasError) {
            return false;
        }
        if ((!array_key_exists($name, $this->indexData)) && (!$this->findValueWithLock($name))) {
            // If the value has explicitly defined as a metric, return 0
            if (array_key_exists($name, $this->metrics)) {
                return 0;
            }
            return false;
        }
        $data = unpack(
            "{$this->metrics[$name]['type']}value",
            shmop_read(
                $this->shmDataId,
                $this->indexData[$name]['offset'],
                Packing::getTypeLength($this->metrics[$name]['type'])
            )
        );
        return $data['value'];
    }

    /**
     * @brief   Override of the __set function.
     *
     * Sets a value in the shared memory segment initialized by this class.
     * Values are only set in shared memory if the name of the value exists in
     * the classes metrics array.
     *
     * If the $value exceeds the maximum value for a integer it will be wrapped
     * around to 0 to avoid PHP converting it to a double.
     *
     * @param   $name     Name of the value to set.
     * @param   $value    The value to set.
     */
    public function __set($name, $value)
    {
        if ($this->readOnly) {
            $this->logger->error('Attempted to write in read only mode');
        }
        if ($this->hasError) {
            return;
        }
        if ($this->shmIndexId === false || $this->shmDataId === false) {
            if (!$this->initializeSharedMemorySegment()) {
                $this->hasError = true;
            }
        }
        if ($this->hasError) {
            return;
        }
        if ((!array_key_exists($name, $this->indexData)) && (!$this->findValueWithLock($name))) {
            if (!$this->addNewValue($name)) {
                $this->hasError = true;
                return;
            }
        }
        $this->validateValue($value, $name);
        shmop_write($this->shmDataId, pack($this->metrics[$name]['type'], $value), $this->indexData[$name]['offset']);
    }

    /**
     * @brief   Increment a counter value in shared memory segment.
     *
     * @param   $key    Key to increment.
     * @param   $value  Value to increment by, defaults to 1.
     */
    public function increment($key, $value = 1)
    {
        $this->__set($key, $this->__get($key) + $value);
    }

    /**
     * @brief   Set a value for a key.
     *
     * @param   $key    Key to set.
     * @param   $value  Value to set.
     */
    public function set($key, $value)
    {
        $this->__set($key, $value);
    }

    /**
     * @brief   Get a value for a key.
     *
     * @param   $key    Key to get.
     *
     * @return  Value for key.
     */
    public function get($key)
    {
        return $this->__get($key);
    }

    /**
     * @brief   Capture timing details of an event for a key
     *
     * This function will update a number of metrics including:
     *   - service_time, an aggregate of the total time recorded for this key.
     *   - responses_times_n, a histogram bin (0 - 5) counting response times
     *     @see: $timingMetrics.
     *   - count, the number of times this key has been recorded.
     *
     * @param   $key        Key to update metrics for
     * @param   $timeTaken  Duration for the event in milliseconds
     */
    public function timing($key, $timeTaken)
    {
        if ($this->readOnly) {
            $this->logger->error('Attempted to write in read only mode');
        }
        if ($this->hasError) {
            return;
        }
        $this->{$key . '.service_time'} += $timeTaken;
        switch (true) {
            case ($timeTaken < 1000):                           // Less than 1 second
                $this->{$key . '.time_taken_0'}++;
                break;
            case ($timeTaken >= 1000 && $timeTaken < 5000):     // Between 1 and 5 seconds
                $this->{$key . '.time_taken_1'}++;
                break;
            case ($timeTaken >= 5000 && $timeTaken < 10000):    // Between 5 and 10 seconds
                $this->{$key . '.time_taken_2'}++;
                break;
            case ($timeTaken >= 10000 && $timeTaken < 20000):   // Between 10 and 20 seconds
                $this->{$key . '.time_taken_3'}++;
                break;
            case ($timeTaken >= 20000 && $timeTaken < 40000):   // Between 20 and 40 seconds
                $this->{$key . '.time_taken_4'}++;
                break;
            case ($timeTaken >= 40000):                         // Greater than 40 seconds
                $this->{$key . '.time_taken_5'}++;
                break;
        }
        $this->{$key . '.timings_count'}++;
    }

    /**
     * @brief   Gets all metrics
     *
     * @return  Associative array of all metrics, or false on error.
     */
    public function getAllMetrics()
    {
        $metrics = array();
        foreach ($this->metrics as $metric => $id) {
            $metrics[$metric] = $this->$metric;
        }
        return $metrics;
    }

    /**
     * @brief   Set the value of all metrics to 0.
     */
    public function clearAllMetrics()
    {
        foreach ($this->metrics as $metric => $id) {
            $this->$metric = 0;
        }
    }

    /**
     * @brief   Getter for the metrics map
     *
     * @return  Associative array of metric keys and IDs
     */
    public function getMetrics()
    {
        return $this->metrics;
    }
}
