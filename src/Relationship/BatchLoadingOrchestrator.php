<?php

namespace Anorm\Relationship;

use Anorm\Relationship\Strategy\QueryStrategySelector;
use Anorm\Relationship\Strategy\FieldSelectionParser;
use Anorm\Relationship\Strategy\QueryStrategyInterface;

/**
 * Orchestrates batch loading of relationships for multiple models
 * 
 * This class coordinates the loading of relationships across multiple models,
 * selecting optimal strategies and managing the batch loading process.
 */
class BatchLoadingOrchestrator
{
    /** @var QueryStrategySelector */
    private $strategySelector;

    /** @var FieldSelectionParser */
    private $fieldParser;

    /** @var array Configuration options */
    private $config;

    public function __construct(
        QueryStrategySelector $strategySelector = null,
        FieldSelectionParser $fieldParser = null,
        array $config = []
    ) {
        $this->strategySelector = $strategySelector ?: new QueryStrategySelector();
        $this->fieldParser = $fieldParser ?: new FieldSelectionParser();
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Load relationships for multiple models using optimal strategies
     * 
     * @param array $models Array of model instances
     * @param array $relationshipSpecs Array of relationship specifications (e.g., ['posts', 'company:name,address'])
     * @return void
     */
    public function loadRelationshipsForModels(array $models, array $relationshipSpecs): void
    {
        if (empty($models) || empty($relationshipSpecs)) {
            return;
        }

        // Parse relationship specifications
        $parsedSpecs = $this->fieldParser->parseMultipleSelections($relationshipSpecs);

        // Group models by class to handle different model types
        $modelsByClass = $this->groupModelsByClass($models);

        foreach ($modelsByClass as $modelClass => $classModels) {
            $this->loadRelationshipsForModelClass($classModels, $parsedSpecs);
        }
    }

    /**
     * Load relationships for models of a single class
     * 
     * @param array $models Array of model instances of the same class
     * @param array $parsedSpecs Parsed relationship specifications
     * @return void
     */
    private function loadRelationshipsForModelClass(array $models, array $parsedSpecs): void
    {
        if (empty($models)) {
            return;
        }

        // Get relationship manager from first model
        $firstModel = reset($models);
        $relationshipManager = $firstModel->getRelationshipManager();

        foreach ($parsedSpecs as $relationshipName => $spec) {
            $relationship = $relationshipManager->getRelationship($relationshipName);
            
            if (!$relationship) {
                // Relationship not defined for this model class, skip
                continue;
            }

            $this->loadSingleRelationship($models, $relationship, $spec);
        }
    }

    /**
     * Load a single relationship for multiple models
     * 
     * @param array $models Array of model instances
     * @param object $relationship The relationship instance
     * @param array $spec Parsed relationship specification
     * @return void
     */
    private function loadSingleRelationship(array $models, $relationship, array $spec): void
    {
        $sourceCount = count($models);
        $fieldSelection = $spec['fields'];

        // Select optimal strategy
        $strategy = $this->strategySelector->selectStrategy($relationship, $sourceCount, $fieldSelection);



        // Execute the selected strategy
        switch ($strategy) {
            case QueryStrategyInterface::STRATEGY_IN_CLAUSE_BATCH:
                $this->executeBatchLoading($models, $relationship, $fieldSelection);
                break;

            case QueryStrategyInterface::STRATEGY_JOIN_WITH_SELECTION:
                // TODO: Implement JOIN strategy in Phase 3
                // For now, fallback to batch loading
                $this->executeBatchLoading($models, $relationship, $fieldSelection);
                break;

            case QueryStrategyInterface::STRATEGY_INDIVIDUAL_LOADING:
            default:
                $this->executeIndividualLoading($models, $relationship);
                break;
        }
    }

    /**
     * Execute batch loading strategy
     *
     * @param array $models Array of model instances
     * @param object $relationship The relationship instance
     * @param array|null $fieldSelection Optional field selection for optimization
     * @return void
     */
    private function executeBatchLoading(array $models, $relationship, ?array $fieldSelection = null): void
    {
        try {
            // Get PDO connection from first model
            $firstModel = reset($models);
            $pdo = $firstModel->getPdo();

            // Load relationships in batch
            $batchResults = $relationship->batchLoad($models, $pdo, $fieldSelection);

            // Distribute results to models
            $relationship->distributeBatchResults($models, $batchResults);

        } catch (\Exception $e) {
            // Fallback to individual loading on error
            if ($this->config['debug_mode']) {
                error_log("Batch loading failed for relationship {$relationship->getPropertyName()}: " . $e->getMessage());
            }
            $this->executeIndividualLoading($models, $relationship);
        }
    }

    /**
     * Execute individual loading strategy (fallback)
     * 
     * @param array $models Array of model instances
     * @param object $relationship The relationship instance
     * @return void
     */
    private function executeIndividualLoading(array $models, $relationship): void
    {
        $relationshipName = $relationship->getPropertyName();
        
        foreach ($models as $model) {
            try {
                $model->loadRelated($relationshipName);
            } catch (\Exception $e) {
                // Log error but continue with other models
                if ($this->config['debug_mode']) {
                    error_log("Individual loading failed for model {$model->id}, relationship {$relationshipName}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Group models by their class name
     * 
     * @param array $models Array of model instances
     * @return array Models grouped by class name
     */
    private function groupModelsByClass(array $models): array
    {
        $grouped = [];
        
        foreach ($models as $model) {
            $className = get_class($model);
            if (!isset($grouped[$className])) {
                $grouped[$className] = [];
            }
            $grouped[$className][] = $model;
        }
        
        return $grouped;
    }



    /**
     * Get default configuration options
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'debug_mode' => false,
            'enable_batch_loading' => true,
            'fallback_to_individual' => true,
            'max_batch_size' => 1000,
        ];
    }

    /**
     * Update configuration options
     * 
     * @param array $config Configuration options to merge
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Get current configuration
     * 
     * @return array Current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get performance statistics for the last batch loading operation
     * 
     * @return array Performance statistics
     */
    public function getPerformanceStats(): array
    {
        // TODO: Implement performance tracking
        return [
            'total_models_processed' => 0,
            'total_relationships_loaded' => 0,
            'strategies_used' => [],
            'total_queries_executed' => 0,
            'time_elapsed' => 0,
        ];
    }
}
