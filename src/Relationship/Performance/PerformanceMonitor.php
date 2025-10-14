<?php

namespace Anorm\Relationship\Performance;

/**
 * Performance monitoring for relationship loading operations
 *
 * Tracks query counts, data transfer volumes, response times, and strategy decisions
 * to provide insights into optimization effectiveness.
 */
class PerformanceMonitor
{
    /** @var array Performance metrics storage */
    private $metrics = [];

    /** @var array Active operation tracking */
    private $activeOperations = [];

    /** @var bool Whether monitoring is enabled */
    private $enabled = true;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
        $this->resetMetrics();
    }

    /**
     * Start tracking a relationship loading operation
     *
     * @param string $operationId Unique identifier for this operation
     * @param array $context Operation context (relationship type, model count, etc.)
     * @return void
     */
    public function startOperation(string $operationId, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->activeOperations[$operationId] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'context' => $context,
            'queries_before' => $this->getCurrentQueryCount(),
        ];
    }

    /**
     * End tracking a relationship loading operation
     *
     * @param string $operationId Operation identifier
     * @param array $results Operation results (loaded models, etc.)
     * @return void
     */
    public function endOperation(string $operationId, array $results = []): void
    {
        if (!$this->enabled || !isset($this->activeOperations[$operationId])) {
            return;
        }

        $operation = $this->activeOperations[$operationId];
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $metrics = [
            'operation_id' => $operationId,
            'duration' => $endTime - $operation['start_time'],
            'memory_used' => $endMemory - $operation['start_memory'],
            'queries_executed' => $this->getCurrentQueryCount() - $operation['queries_before'],
            'context' => $operation['context'],
            'results' => $results,
            'timestamp' => $endTime
        ];

        $this->recordMetrics($metrics);
        unset($this->activeOperations[$operationId]);
    }

    /**
     * Record strategy selection decision
     *
     * @param string $relationshipType Type of relationship
     * @param string $selectedStrategy Strategy that was selected
     * @param array $decisionFactors Factors that influenced the decision
     * @return void
     */
    public function recordStrategySelection(string $relationshipType, string $selectedStrategy, array $decisionFactors): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!isset($this->metrics['strategy_selections'])) {
            $this->metrics['strategy_selections'] = [];
        }

        $this->metrics['strategy_selections'][] = [
            'relationship_type' => $relationshipType,
            'selected_strategy' => $selectedStrategy,
            'decision_factors' => $decisionFactors,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Record query count reduction
     *
     * @param int $beforeCount Query count before optimization
     * @param int $afterCount Query count after optimization
     * @param string $strategy Strategy used
     * @return void
     */
    public function recordQueryReduction(int $beforeCount, int $afterCount, string $strategy): void
    {
        if (!$this->enabled) {
            return;
        }

        $reduction = $beforeCount - $afterCount;
        $reductionPercentage = $beforeCount > 0 ? ($reduction / $beforeCount) * 100 : 0;

        $this->metrics['query_reductions'][] = [
            'before_count' => $beforeCount,
            'after_count' => $afterCount,
            'reduction' => $reduction,
            'reduction_percentage' => $reductionPercentage,
            'strategy' => $strategy,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Record data transfer volume
     *
     * @param int $bytesTransferred Number of bytes transferred
     * @param string $strategy Strategy used
     * @param array $context Additional context
     * @return void
     */
    public function recordDataTransfer(int $bytesTransferred, string $strategy, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->metrics['data_transfers'][] = [
            'bytes_transferred' => $bytesTransferred,
            'strategy' => $strategy,
            'context' => $context,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Get comprehensive performance report
     *
     * @return array Performance analysis report
     */
    public function getPerformanceReport(): array
    {
        if (!$this->enabled) {
            return ['monitoring_disabled' => true];
        }

        return [
            'summary' => $this->generateSummary(),
            'query_optimization' => $this->analyzeQueryOptimization(),
            'strategy_effectiveness' => $this->analyzeStrategyEffectiveness(),
            'data_transfer_analysis' => $this->analyzeDataTransfer(),
            'response_time_analysis' => $this->analyzeResponseTimes(),
            'recommendations' => $this->generateRecommendations()
        ];
    }

    /**
     * Generate performance summary
     *
     * @return array Summary metrics
     */
    private function generateSummary(): array
    {
        $totalOperations = count($this->metrics['operations']);
        $totalDuration = array_sum(array_column($this->metrics['operations'], 'duration'));
        $totalQueries = array_sum(array_column($this->metrics['operations'], 'queries_executed'));
        $totalMemory = array_sum(array_column($this->metrics['operations'], 'memory_used'));

        return [
            'total_operations' => $totalOperations,
            'total_duration' => $totalDuration,
            'average_duration' => $totalOperations > 0 ? $totalDuration / $totalOperations : 0,
            'total_queries' => $totalQueries,
            'average_queries_per_operation' => $totalOperations > 0 ? $totalQueries / $totalOperations : 0,
            'total_memory_used' => $totalMemory,
            'average_memory_per_operation' => $totalOperations > 0 ? $totalMemory / $totalOperations : 0
        ];
    }

    /**
     * Analyze query optimization effectiveness
     *
     * @return array Query optimization analysis
     */
    private function analyzeQueryOptimization(): array
    {
        if (empty($this->metrics['query_reductions'])) {
            return ['no_data' => true];
        }

        $reductions = $this->metrics['query_reductions'];
        $totalReduction = array_sum(array_column($reductions, 'reduction'));
        $averageReduction = array_sum(array_column($reductions, 'reduction_percentage')) / count($reductions);

        return [
            'total_queries_saved' => $totalReduction,
            'average_reduction_percentage' => $averageReduction,
            'optimization_instances' => count($reductions),
            'best_reduction' => max(array_column($reductions, 'reduction_percentage')),
            'worst_reduction' => min(array_column($reductions, 'reduction_percentage'))
        ];
    }

    /**
     * Analyze strategy effectiveness
     *
     * @return array Strategy analysis
     */
    private function analyzeStrategyEffectiveness(): array
    {
        if (empty($this->metrics['strategy_selections'])) {
            return ['no_data' => true];
        }

        $strategies = array_column($this->metrics['strategy_selections'], 'selected_strategy');
        $strategyCounts = array_count_values($strategies);

        return [
            'strategy_usage' => $strategyCounts,
            'most_used_strategy' => array_keys($strategyCounts, max($strategyCounts))[0],
            'total_selections' => count($strategies)
        ];
    }

    /**
     * Analyze data transfer patterns
     *
     * @return array Data transfer analysis
     */
    private function analyzeDataTransfer(): array
    {
        if (empty($this->metrics['data_transfers'])) {
            return ['no_data' => true];
        }

        $transfers = $this->metrics['data_transfers'];
        $totalBytes = array_sum(array_column($transfers, 'bytes_transferred'));
        $averageBytes = $totalBytes / count($transfers);

        return [
            'total_bytes_transferred' => $totalBytes,
            'average_bytes_per_operation' => $averageBytes,
            'transfer_operations' => count($transfers),
            'largest_transfer' => max(array_column($transfers, 'bytes_transferred')),
            'smallest_transfer' => min(array_column($transfers, 'bytes_transferred'))
        ];
    }

    /**
     * Analyze response times
     *
     * @return array Response time analysis
     */
    private function analyzeResponseTimes(): array
    {
        if (empty($this->metrics['operations'])) {
            return ['no_data' => true];
        }

        $durations = array_column($this->metrics['operations'], 'duration');
        sort($durations);

        $count = count($durations);
        $median = $count % 2 === 0
            ? ($durations[$count / 2 - 1] + $durations[$count / 2]) / 2
            : $durations[floor($count / 2)];

        return [
            'average_response_time' => array_sum($durations) / $count,
            'median_response_time' => $median,
            'fastest_response' => min($durations),
            'slowest_response' => max($durations),
            'p95_response_time' => $durations[floor($count * 0.95)]
        ];
    }

    /**
     * Generate optimization recommendations
     *
     * @return array Recommendations for improvement
     */
    private function generateRecommendations(): array
    {
        $recommendations = [];

        // Analyze query patterns
        if (!empty($this->metrics['query_reductions'])) {
            $avgReduction = array_sum(array_column($this->metrics['query_reductions'], 'reduction_percentage'))
                          / count($this->metrics['query_reductions']);

            if ($avgReduction < 50) {
                $recommendations[] = "Query reduction is below 50%. Consider reviewing strategy selection criteria.";
            }
        }

        // Analyze response times
        if (!empty($this->metrics['operations'])) {
            $avgDuration = array_sum(array_column($this->metrics['operations'], 'duration'))
                         / count($this->metrics['operations']);

            if ($avgDuration > 0.1) { // 100ms
                $recommendations[] = "Average response time is high. Consider optimizing database indexes or query strategies.";
            }
        }

        // Analyze strategy usage
        if (!empty($this->metrics['strategy_selections'])) {
            $strategies = array_column($this->metrics['strategy_selections'], 'selected_strategy');
            $strategyCounts = array_count_values($strategies);

            if (
                isset($strategyCounts['individual_loading']) &&
                $strategyCounts['individual_loading'] / count($strategies) > 0.5
            ) {
                $recommendations[] = "High usage of individual loading strategy. Review batch loading thresholds.";
            }
        }

        return $recommendations;
    }

    /**
     * Record operation metrics
     *
     * @param array $metrics Metrics to record
     * @return void
     */
    private function recordMetrics(array $metrics): void
    {
        $this->metrics['operations'][] = $metrics;
    }

    /**
     * Get current query count (simplified implementation)
     *
     * @return int Current query count
     */
    private function getCurrentQueryCount(): int
    {
        // This is a simplified implementation
        // In practice, this would integrate with PDO or a query logger
        static $queryCount = 0;
        return $queryCount++;
    }

    /**
     * Reset all metrics
     *
     * @return void
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'operations' => [],
            'strategy_selections' => [],
            'query_reductions' => [],
            'data_transfers' => []
        ];
    }

    /**
     * Enable or disable monitoring
     *
     * @param bool $enabled Whether to enable monitoring
     * @return void
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
