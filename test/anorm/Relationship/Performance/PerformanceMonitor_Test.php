<?php

namespace Anorm\Test;

use Anorm\Relationship\Performance\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

class PerformanceMonitor_Test extends TestCase
{
    private $monitor;

    protected function setUp(): void
    {
        $this->monitor = new PerformanceMonitor(true);
    }

    public function testBasicOperationTracking()
    {
        $operationId = 'test_operation';
        $context = ['model_count' => 10, 'relationship' => 'posts'];

        $this->monitor->startOperation($operationId, $context);

        // Simulate some work
        usleep(1000); // 1ms

        $results = ['loaded_models' => 5];
        $this->monitor->endOperation($operationId, $results);

        $report = $this->monitor->getPerformanceReport();

        $this->assertArrayHasKey('summary', $report);
        $this->assertEquals(1, $report['summary']['total_operations']);
        $this->assertGreaterThan(0, $report['summary']['total_duration']);
    }

    public function testStrategySelectionRecording()
    {
        $this->monitor->recordStrategySelection(
            'oneHasMany',
            'in_clause_batch',
            ['source_count' => 50, 'field_selection' => true]
        );

        $this->monitor->recordStrategySelection(
            'manyHasOne',
            'join_with_selection',
            ['source_count' => 20, 'field_selection' => true]
        );

        $report = $this->monitor->getPerformanceReport();

        $this->assertArrayHasKey('strategy_effectiveness', $report);
        $this->assertEquals(2, $report['strategy_effectiveness']['total_selections']);
        $this->assertArrayHasKey('strategy_usage', $report['strategy_effectiveness']);
    }

    public function testQueryReductionRecording()
    {
        $this->monitor->recordQueryReduction(100, 5, 'in_clause_batch');
        $this->monitor->recordQueryReduction(50, 10, 'join_with_selection');

        $report = $this->monitor->getPerformanceReport();

        $this->assertArrayHasKey('query_optimization', $report);
        $this->assertEquals(135, $report['query_optimization']['total_queries_saved']); // 95 + 40 = 135
        $this->assertEquals(2, $report['query_optimization']['optimization_instances']);
        $this->assertGreaterThan(0, $report['query_optimization']['average_reduction_percentage']);
    }

    public function testDataTransferRecording()
    {
        $this->monitor->recordDataTransfer(1024, 'in_clause_batch', ['field_count' => 5]);
        $this->monitor->recordDataTransfer(512, 'join_with_selection', ['field_count' => 2]);

        $report = $this->monitor->getPerformanceReport();

        $this->assertArrayHasKey('data_transfer_analysis', $report);
        $this->assertEquals(1536, $report['data_transfer_analysis']['total_bytes_transferred']);
        $this->assertEquals(768, $report['data_transfer_analysis']['average_bytes_per_operation']);
        $this->assertEquals(2, $report['data_transfer_analysis']['transfer_operations']);
    }

    public function testPerformanceReportGeneration()
    {
        // Generate comprehensive test data
        $this->monitor->startOperation('op1', ['model_count' => 10]);
        usleep(2000); // 2ms
        $this->monitor->endOperation('op1', ['loaded_models' => 10]);

        $this->monitor->startOperation('op2', ['model_count' => 20]);
        usleep(3000); // 3ms
        $this->monitor->endOperation('op2', ['loaded_models' => 20]);

        $this->monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);
        $this->monitor->recordQueryReduction(50, 5, 'in_clause_batch');
        $this->monitor->recordDataTransfer(2048, 'in_clause_batch');

        $report = $this->monitor->getPerformanceReport();

        // Verify all sections are present
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('query_optimization', $report);
        $this->assertArrayHasKey('strategy_effectiveness', $report);
        $this->assertArrayHasKey('data_transfer_analysis', $report);
        $this->assertArrayHasKey('response_time_analysis', $report);
        $this->assertArrayHasKey('recommendations', $report);

        // Verify summary calculations
        $this->assertEquals(2, $report['summary']['total_operations']);
        $this->assertIsNumeric($report['summary']['total_memory_used']); // Memory usage varies in test environment
    }

    public function testResponseTimeAnalysis()
    {
        // Create operations with different durations
        $durations = [0.001, 0.002, 0.003, 0.004, 0.005]; // 1-5ms

        foreach ($durations as $i => $duration) {
            $this->monitor->startOperation("op_{$i}");
            usleep((int)($duration * 1000000)); // Convert to microseconds
            $this->monitor->endOperation("op_{$i}");
        }

        $report = $this->monitor->getPerformanceReport();
        $responseAnalysis = $report['response_time_analysis'];

        $this->assertArrayHasKey('average_response_time', $responseAnalysis);
        $this->assertArrayHasKey('median_response_time', $responseAnalysis);
        $this->assertArrayHasKey('fastest_response', $responseAnalysis);
        $this->assertArrayHasKey('slowest_response', $responseAnalysis);
        $this->assertArrayHasKey('p95_response_time', $responseAnalysis);

        $this->assertGreaterThan(0, $responseAnalysis['average_response_time']);
        $this->assertGreaterThan(0, $responseAnalysis['median_response_time']);
    }

    public function testRecommendationGeneration()
    {
        // Create scenarios that should trigger recommendations

        // High individual loading usage
        for ($i = 0; $i < 10; $i++) {
            $this->monitor->recordStrategySelection('oneHasMany', 'individual_loading', []);
        }
        $this->monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);

        // Low query reduction
        $this->monitor->recordQueryReduction(10, 8, 'in_clause_batch'); // Only 20% reduction

        // Slow operations
        $this->monitor->startOperation('slow_op');
        usleep(150000); // 150ms - should trigger recommendation
        $this->monitor->endOperation('slow_op');

        $report = $this->monitor->getPerformanceReport();
        $recommendations = $report['recommendations'];

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);

        // Should have recommendations about individual loading and slow responses
        $hasIndividualLoadingRecommendation = false;
        $hasSlowResponseRecommendation = false;

        foreach ($recommendations as $recommendation) {
            if (strpos($recommendation, 'individual loading') !== false) {
                $hasIndividualLoadingRecommendation = true;
            }
            if (strpos($recommendation, 'response time') !== false) {
                $hasSlowResponseRecommendation = true;
            }
        }

        $this->assertTrue($hasIndividualLoadingRecommendation);
        $this->assertTrue($hasSlowResponseRecommendation);
    }

    public function testDisabledMonitoring()
    {
        $disabledMonitor = new PerformanceMonitor(false);

        $disabledMonitor->startOperation('test_op');
        $disabledMonitor->endOperation('test_op');
        $disabledMonitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);

        $report = $disabledMonitor->getPerformanceReport();

        $this->assertArrayHasKey('monitoring_disabled', $report);
        $this->assertTrue($report['monitoring_disabled']);
    }

    public function testSetEnabled()
    {
        // Start with monitoring enabled
        $this->monitor->startOperation('test_op');
        $this->monitor->endOperation('test_op');

        $report1 = $this->monitor->getPerformanceReport();
        $this->assertEquals(1, $report1['summary']['total_operations']);

        // Disable monitoring
        $this->monitor->setEnabled(false);
        $this->monitor->startOperation('test_op_2');
        $this->monitor->endOperation('test_op_2');

        $report2 = $this->monitor->getPerformanceReport();
        $this->assertArrayHasKey('monitoring_disabled', $report2);

        // Re-enable monitoring
        $this->monitor->setEnabled(true);
        $this->monitor->startOperation('test_op_3');
        $this->monitor->endOperation('test_op_3');

        $report3 = $this->monitor->getPerformanceReport();
        $this->assertEquals(2, $report3['summary']['total_operations']); // Should be 1 + 1 (skipped the disabled one)
    }

    public function testResetMetrics()
    {
        // Generate some data
        $this->monitor->startOperation('test_op');
        $this->monitor->endOperation('test_op');
        $this->monitor->recordStrategySelection('oneHasMany', 'in_clause_batch', []);

        $report1 = $this->monitor->getPerformanceReport();
        $this->assertEquals(1, $report1['summary']['total_operations']);

        // Reset metrics
        $this->monitor->resetMetrics();

        $report2 = $this->monitor->getPerformanceReport();
        $this->assertEquals(0, $report2['summary']['total_operations']);
    }

    public function testConcurrentOperations()
    {
        // Test multiple overlapping operations
        $this->monitor->startOperation('op1');
        $this->monitor->startOperation('op2');

        usleep(1000);
        $this->monitor->endOperation('op1');

        usleep(1000);
        $this->monitor->endOperation('op2');

        $report = $this->monitor->getPerformanceReport();
        $this->assertEquals(2, $report['summary']['total_operations']);
    }

    public function testOperationWithoutEnd()
    {
        // Start an operation but don't end it
        $this->monitor->startOperation('incomplete_op');

        // End a different operation that was never started
        $this->monitor->endOperation('nonexistent_op');

        $report = $this->monitor->getPerformanceReport();
        $this->assertEquals(0, $report['summary']['total_operations']);
    }
}
