<?php

declare(strict_types=1);

namespace MessageMedia\shmop\Tests;

use PHPUnit\Framework\TestCase;
use MessageMedia\shmop\metrics\MetricsLogger;

class TestMetricsLogger extends \MessageMedia\shmop\metrics\MetricsLogger {

    public function __construct() {
        $name    = 'unittest';
        $version = 100;

        $keyIds = array();

        $keyIds[] = array(
            'type'         => MetricsLogger::METRIC_TYPE_COUNTER,
            'name'         => 'unitTestCounter',
            'pcp_cluster'  => 0,
            'pcp_item'     => 0,
            'pcp_instance' => MetricsLogger::INSTANCE_DOMAIN_NULL,
        );

        $keyIds[] = array(
            'type'         => MetricsLogger::METRIC_TYPE_TIMER,
            'name'         => 'unitTestTimer',
            'pcp_cluster'  => 1,
            'pcp_item'     => 10,
            'pcp_instance' => 1,
        );

        parent::__construct($name, $keyIds, $version, MetricsLogger::MODE_READ_WRITE);
    }
}

class InstrumentedTestMetricsLogger extends TestMetricsLogger {
     protected function initializeSharedMemorySegment() {
        return false;
     }
}

class MetricsLoggerTest extends TestCase {

    public $expectedIpcKeyFile = '/var/tmp/unittest.metrics';

    public function cleanUp() {
        if (file_exists($this->expectedIpcKeyFile)) {
            @$shmIndexId = shmop_open(ftok($this->expectedIpcKeyFile, MetricsLogger::FTOK_PROJECT_INDEX_IDENTIFIER), 'w', 0, 0);
            if ($shmIndexId) {
                shmop_delete($shmIndexId);
            }
            @$shmDataId = shmop_open(ftok($this->expectedIpcKeyFile, MetricsLogger::FTOK_PROJECT_DATA_IDENTIFIER), 'w', 0, 0);
            if ($shmDataId) {
                shmop_delete($shmDataId);
            }
            unlink($this->expectedIpcKeyFile);
        }

    }

    public function setup() {
        parent::setUp();
        $this->cleanUp();
    }

    public function tearDown() {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testConstructor() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals('unittest', $metricsLogger->getName());
        $this->assertTrue(is_array($metricsLogger->getMetrics()));
        $this->assertEquals($this->expectedIpcKeyFile, $metricsLogger->getIpcKeyFile());

        $metricsLogger = new InstrumentedTestMetricsLogger();
        $this->assertTrue($metricsLogger->isError());
    }

    public function testInitializeSharedMemorySegment() {
        $metricsLogger = new TestMetricsLogger();
        // Inexplicit check for return value of initializeSharedMemorySegment().
        $this->assertFalse($metricsLogger->isError());
        // Check the ipcKeyFile exists.
        $this->assertTrue(file_exists($this->expectedIpcKeyFile));
        $shmIndexId = shmop_open(ftok($this->expectedIpcKeyFile, MetricsLogger::FTOK_PROJECT_INDEX_IDENTIFIER), 'a', 0, 0);
        $indexData = unpack('Lversion/LnextIndexOffset/LnextDataOffset', shmop_read($shmIndexId, 0, 12));
        $this->assertArrayHasKey('version', $indexData);
        $this->assertArrayHasKey('nextIndexOffset', $indexData);
        $this->assertArrayHasKey('nextDataOffset', $indexData);

        $this->assertEquals(100, $indexData['version']);
        $this->assertEquals(12,  $indexData['nextIndexOffset']);
        $this->assertEquals(0,   $indexData['nextDataOffset']);

        // Change the ipcKeyFile to an invalid name and ensure it can't be touched
        /*$initializeSharedMemorySegment = TestUtils::getPrivateMethod($metricsLogger, 'initializeSharedMemorySegment');
        $ipcKeyFile = TestUtils::getPrivateProperty($metricsLogger, 'ipcKeyFile');
        $ipcKeyFile->setValue($metricsLogger, '/non/existent/dir/file');
        $this->assertFalse($initializeSharedMemorySegment->invoke($metricsLogger));*/
    }

    public function testParseMetrics() {
        $metricsLogger = new TestMetricsLogger();
        // parseMetrics() has been called by the constructor
        $metrics = $metricsLogger->getMetrics();
        $this->assertTrue(is_array($metrics));
        $this->assertEquals(1 + 8, count($metrics)); // 1 unitTestCounter and 8 unitTestTimer

        $metricsToCheck = array(
            'unitTestCounter',
            'unitTestTimer.service_time',
            'unitTestTimer.time_taken_0',
            'unitTestTimer.time_taken_1',
            'unitTestTimer.time_taken_2',
            'unitTestTimer.time_taken_3',
            'unitTestTimer.time_taken_4',
            'unitTestTimer.time_taken_5',
            'unitTestTimer.timings_count',
        );

        foreach ($metricsToCheck as $metricToCheck) {
            $this->assertArrayHasKey($metricToCheck, $metrics);
            $this->assertTrue(is_array($metrics[$metricToCheck]));
            $this->assertEquals(5, count($metrics[$metricToCheck]));
            $this->assertEquals(0, $metrics[$metricToCheck]['flags']);
            $this->assertEquals('L', $metrics[$metricToCheck]['type']);
        }

        $this->assertEquals(0,  $metrics['unitTestCounter']['pcp_cluster']);
        $this->assertEquals(0,  $metrics['unitTestCounter']['pcp_item']);
        $this->assertEquals(-1, $metrics['unitTestCounter']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.service_time']['pcp_cluster']);
        $this->assertEquals(10,  $metrics['unitTestTimer.service_time']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.service_time']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_0']['pcp_cluster']);
        $this->assertEquals(11,  $metrics['unitTestTimer.time_taken_0']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_0']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_1']['pcp_cluster']);
        $this->assertEquals(12,  $metrics['unitTestTimer.time_taken_1']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_1']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_2']['pcp_cluster']);
        $this->assertEquals(13,  $metrics['unitTestTimer.time_taken_2']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_2']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_3']['pcp_cluster']);
        $this->assertEquals(14,  $metrics['unitTestTimer.time_taken_3']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_3']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_4']['pcp_cluster']);
        $this->assertEquals(15,  $metrics['unitTestTimer.time_taken_4']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_4']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.time_taken_5']['pcp_cluster']);
        $this->assertEquals(16,  $metrics['unitTestTimer.time_taken_5']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.time_taken_5']['pcp_instance']);

        $this->assertEquals(1,  $metrics['unitTestTimer.timings_count']['pcp_cluster']);
        $this->assertEquals(17,  $metrics['unitTestTimer.timings_count']['pcp_item']);
        $this->assertEquals(1, $metrics['unitTestTimer.timings_count']['pcp_instance']);
    }

    public function testValidateMetricConfig() {
        $metricsLogger = new TestMetricsLogger();
        $class = new \ReflectionClass($metricsLogger);
        $validateMetricConfig = $class->getMethod('validateMetricConfig');
        $validateMetricConfig->setAccessible(true);

        $pcpHash = array();

        // Missing type
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array(), &$pcpHash)));

        // Missing type
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER), &$pcpHash)));

        // Counter with no name
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0), &$pcpHash)));

        // Not a timer, without an item
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0, 'name' => 'name'), &$pcpHash)));

        $config = array(
            'type'         => MetricsLogger::METRIC_TYPE_TIMER,
            'pcp_cluster'  => 0,
            'name'         => 'name',
            'pcp_item'     => 100,
            'pcp_instance' => 100,
        );
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0, $config, &$pcpHash)));
        $this->assertEquals(100, $config['pcp_item']);

        $pcpHash = array();
        // Invalid pcp_cluster
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 65536, 'name' => 'name', 'pcp_item' => 1,     'pcp_instance' => 1),           &$pcpHash)));
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => -1,    'name' => 'name', 'pcp_item' => 1,     'pcp_instance' => 1),           &$pcpHash)));
        // Valid pcp_cluster
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 65535, 'name' => 'name', 'pcp_item' => 1,     'pcp_instance' => 1),           &$pcpHash)));
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 1,     'pcp_instance' => 1),           &$pcpHash)));

        $pcpHash = array();
        // Inalid pcp_item
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 65536, 'pcp_instance' => 1),           &$pcpHash)));
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => -1,    'pcp_instance' => 1),           &$pcpHash)));
        // Valid pcp_item
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 65535, 'pcp_instance' => 1),           &$pcpHash)));
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 0,     'pcp_instance' => 1),           &$pcpHash)));

        $pcpHash = array();
        // Inalid pcp_instance
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 0,     'pcp_instance' => 2147483648),  &$pcpHash)));
        $this->assertFalse($validateMetricConfig->invokeArgs($metricsLogger, array(0, array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 0,     'pcp_instance' => -2147483649), &$pcpHash)));
        // Valid pcp_instance
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 0,     'pcp_instance' => 2147483647),  &$pcpHash)));
        $this->assertTrue($validateMetricConfig->invokeArgs($metricsLogger, array(0,  array('type' => MetricsLogger::METRIC_TYPE_COUNTER, 'pcp_cluster' => 0,     'name' => 'name', 'pcp_item' => 0,     'pcp_instance' => -2147483648), &$pcpHash)));
    }

    public function test__get() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals(0, $metricsLogger->unitTestCounter);

        $metricsLogger->deleteSharedMemory();
        $this->assertEquals(0, $metricsLogger->unitTestCounter);

        $this->assertFalse($metricsLogger->nonExistentValue);

        $metricsLogger->deleteSharedMemory();
        touch($this->expectedIpcKeyFile);
        $ipcKeyFileHandle = fopen($this->expectedIpcKeyFile, 'r');
        flock($ipcKeyFileHandle, LOCK_EX);
        $this->assertFalse($metricsLogger->unitTestCounter);
        flock($ipcKeyFileHandle, LOCK_UN);
        fclose($ipcKeyFileHandle);
    }

    public function test__set() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals(0, $metricsLogger->unitTestCounter);
        $this->assertEquals(1, ++$metricsLogger->unitTestCounter);

        $metricsLogger->nonExistentValue++;

        $metricsLogger->deleteSharedMemory();
        touch($this->expectedIpcKeyFile);
        $ipcKeyFileHandle = fopen($this->expectedIpcKeyFile, 'r');
        flock($ipcKeyFileHandle, LOCK_EX);
        $metricsLogger->unitTestCounter++;
        flock($ipcKeyFileHandle, LOCK_UN);
        fclose($ipcKeyFileHandle);
        $this->assertEquals(0, $metricsLogger->unitTestCounter);
    }

    public function testValidateValue() {
        $metricsLogger = new TestMetricsLogger();
        $class = new \ReflectionClass($metricsLogger);
        $validateValue = $class->getMethod('validateValue');
        $validateValue->setAccessible(true);

        $value = 'string';
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'name'));
        $this->assertEquals(0, $value);

        $value = array();
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'name'));
        $this->assertEquals(0, $value);

        $value = true;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'name'));
        $this->assertEquals(0, $value);

        $value = 1.1;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestCounter'));
        $this->assertEquals(0, $value);

        $value = min(PHP_INT_MAX, 4294967295);
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestCounter'));
        $this->assertEquals(0, $value);

        $value = min(PHP_INT_MAX, 4294967295) + 1;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestCounter'));
        $this->assertEquals(0, $value);

        $value = min(PHP_INT_MAX, 4294967295) - 1;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestCounter'));
        $this->assertEquals(min(PHP_INT_MAX, 4294967295) - 1, $value);

        $value = -1;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestCounter'));
        $this->assertEquals(0, $value);

        $value = 1.1;
        $validateValue->invokeArgs($metricsLogger, array(&$value, 'unitTestTimer.service_time'));
        $this->assertEquals(0, $value);
    }

    public function testIncrement() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals(0, $metricsLogger->unitTestCounter);
        $metricsLogger->increment('unitTestCounter', 1);
        $this->assertEquals(1, $metricsLogger->unitTestCounter);
        $metricsLogger->increment('unitTestCounter', 100);
        $this->assertEquals(101, $metricsLogger->unitTestCounter);
    }

    public function testSet() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals(0, $metricsLogger->unitTestCounter);
        $metricsLogger->set('unitTestCounter', 1);
        $this->assertEquals(1, $metricsLogger->unitTestCounter);
        $metricsLogger->set('unitTestCounter', 100);
        $this->assertEquals(100, $metricsLogger->unitTestCounter);
    }

    public function testTiming() {
        $metricsLogger = new TestMetricsLogger();
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 100);
        $this->assertEquals(100, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 1001);
        $this->assertEquals(1101, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(2, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 5001);
        $this->assertEquals(6102, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(3, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 10001);
        $this->assertEquals(16103, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(4, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 20001);
        $this->assertEquals(36104, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(0, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(5, $metricsLogger->{'unitTestTimer.timings_count'});

        $metricsLogger->timing('unitTestTimer', 40001);
        $this->assertEquals(76105, $metricsLogger->{'unitTestTimer.service_time'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_0'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_1'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_2'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_3'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_4'});
        $this->assertEquals(1, $metricsLogger->{'unitTestTimer.time_taken_5'});
        $this->assertEquals(6, $metricsLogger->{'unitTestTimer.timings_count'});
    }

    public function testGetAllMetrics() {
        $metricsLogger = new TestMetricsLogger();
        $metricsData = $metricsLogger->getAllMetrics();
        $this->assertTrue(is_array($metricsData));
        $this->assertEquals(9, count($metricsData));

        $metrics = $metricsLogger->getMetrics();

        foreach ($metricsData as $key => $metric) {
            $this->assertArrayHasKey($key, $metrics);
            $this->assertEquals(0, $metricsData[$key]);
        }
    }

    public function testClearAllMetrics() {
        $metricsLogger = new TestMetricsLogger();
        $metricsLogger->unitTestCounter++;
        $metricsLogger->timing('unitTestTimer', 100);

        $metricsLogger->clearAllMetrics();

        $metricsData = $metricsLogger->getAllMetrics();
        $metrics = $metricsLogger->getMetrics();

        foreach ($metrics as $key => $metric) {
            $this->assertArrayHasKey($key, $metrics);
            $this->assertEquals(0, $metricsData[$key]);
        }
    }
}
