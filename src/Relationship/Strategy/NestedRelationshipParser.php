<?php

namespace Anorm\Relationship\Strategy;

/**
 * Parser for nested relationship specifications
 * 
 * Handles syntax like:
 * - 'posts.comments'
 * - 'posts.comments.author'
 * - 'company.users.posts'
 */
class NestedRelationshipParser
{
    /** @var array Circular reference detection */
    private $loadingStack = [];

    /**
     * Parse nested relationship specifications
     * 
     * @param array $relationshipSpecs Array of relationship specifications
     * @return array Parsed nested structure
     */
    public function parseNestedSpecs(array $relationshipSpecs): array
    {
        $parsed = [];
        
        foreach ($relationshipSpecs as $spec) {
            $this->parseNestedSpec($spec, $parsed);
        }
        
        return $parsed;
    }

    /**
     * Parse a single nested relationship specification
     * 
     * @param string $spec Relationship specification (e.g., 'posts.comments.author')
     * @param array &$parsed Reference to parsed structure
     * @return void
     */
    private function parseNestedSpec(string $spec, array &$parsed): void
    {
        // Handle field selection syntax: 'posts:id,title.comments:id,content'
        $parts = explode('.', $spec);
        $current = &$parsed;
        
        foreach ($parts as $part) {
            // Parse field selection for this level
            $fieldParser = new FieldSelectionParser();
            $parsedPart = $fieldParser->parseFieldSelection($part);
            
            $relationshipName = $parsedPart['relationship'];
            $fields = $parsedPart['fields'];
            
            if (!isset($current[$relationshipName])) {
                $current[$relationshipName] = [
                    'fields' => $fields,
                    'nested' => []
                ];
            } else {
                // Merge field selections if both exist
                if ($fields !== null && $current[$relationshipName]['fields'] !== null) {
                    $current[$relationshipName]['fields'] = array_unique(
                        array_merge($current[$relationshipName]['fields'], $fields)
                    );
                } elseif ($fields !== null) {
                    $current[$relationshipName]['fields'] = $fields;
                }
            }
            
            $current = &$current[$relationshipName]['nested'];
        }
    }

    /**
     * Load nested relationships for a set of models
     * 
     * @param array $models Array of model instances
     * @param array $nestedSpecs Parsed nested relationship specifications
     * @param int $maxDepth Maximum nesting depth to prevent infinite recursion
     * @return void
     */
    public function loadNestedRelationships(array $models, array $nestedSpecs, int $maxDepth = 5): void
    {
        if (empty($models) || empty($nestedSpecs) || $maxDepth <= 0) {
            return;
        }
        
        foreach ($nestedSpecs as $relationshipName => $spec) {
            $this->loadSingleNestedRelationship($models, $relationshipName, $spec, $maxDepth);
        }
    }

    /**
     * Load a single nested relationship
     * 
     * @param array $models Source models
     * @param string $relationshipName Name of the relationship to load
     * @param array $spec Relationship specification
     * @param int $maxDepth Remaining depth
     * @return void
     */
    private function loadSingleNestedRelationship(array $models, string $relationshipName, array $spec, int $maxDepth): void
    {
        // Detect circular references
        $circularKey = $this->generateCircularKey($models, $relationshipName);
        if (in_array($circularKey, $this->loadingStack, true)) {
            return; // Skip circular reference
        }
        
        $this->loadingStack[] = $circularKey;
        
        try {
            // Load the immediate relationship
            $this->loadImmediateRelationship($models, $relationshipName, $spec['fields']);
            
            // If there are nested relationships, load them recursively
            if (!empty($spec['nested'])) {
                $relatedModels = $this->extractRelatedModels($models, $relationshipName);
                $this->loadNestedRelationships($relatedModels, $spec['nested'], $maxDepth - 1);
            }
        } finally {
            // Remove from loading stack
            array_pop($this->loadingStack);
        }
    }

    /**
     * Load immediate relationship for models
     * 
     * @param array $models Source models
     * @param string $relationshipName Relationship to load
     * @param array|null $fields Field selection
     * @return void
     */
    private function loadImmediateRelationship(array $models, string $relationshipName, ?array $fields): void
    {
        if (empty($models)) {
            return;
        }
        
        $firstModel = reset($models);
        $relationshipManager = $firstModel->getRelationshipManager();
        $relationship = $relationshipManager->getRelationship($relationshipName);
        
        if (!$relationship) {
            return; // Relationship not found
        }
        
        // Load using batch loading with field selection
        $batchResults = $relationship->batchLoad($models, $firstModel->getPdo(), $fields);
        $relationship->distributeBatchResults($models, $batchResults, $relationshipName);
    }

    /**
     * Extract related models from loaded relationships
     * 
     * @param array $models Source models
     * @param string $relationshipName Relationship name
     * @return array Related models
     */
    private function extractRelatedModels(array $models, string $relationshipName): array
    {
        $relatedModels = [];
        
        foreach ($models as $model) {
            if (isset($model->{$relationshipName})) {
                $related = $model->{$relationshipName};
                
                if (is_array($related)) {
                    // One-to-many or many-to-many relationship
                    $relatedModels = array_merge($relatedModels, $related);
                } elseif ($related !== null) {
                    // Many-to-one or one-to-one relationship
                    $relatedModels[] = $related;
                }
            }
        }
        
        return $relatedModels;
    }

    /**
     * Generate a key for circular reference detection
     * 
     * @param array $models Source models
     * @param string $relationshipName Relationship name
     * @return string Circular reference key
     */
    private function generateCircularKey(array $models, string $relationshipName): string
    {
        if (empty($models)) {
            return $relationshipName;
        }
        
        $firstModel = reset($models);
        $modelClass = get_class($firstModel);
        
        return $modelClass . '.' . $relationshipName;
    }

    /**
     * Validate nested relationship specification
     * 
     * @param string $spec Relationship specification
     * @return array Validation result
     */
    public function validateNestedSpec(string $spec): array
    {
        $errors = [];
        $warnings = [];
        
        // Check for excessive nesting depth
        $depth = substr_count($spec, '.') + 1;
        if ($depth > 5) {
            $warnings[] = "Deep nesting detected ({$depth} levels). Consider optimizing for performance.";
        }
        
        // Check for potential circular references in spec
        $parts = explode('.', $spec);
        $seen = [];
        foreach ($parts as $part) {
            $relationshipName = explode(':', $part)[0]; // Remove field selection
            if (in_array($relationshipName, $seen, true)) {
                $errors[] = "Potential circular reference detected: {$relationshipName} appears multiple times.";
            }
            $seen[] = $relationshipName;
        }
        
        // Validate field selection syntax
        $fieldParser = new FieldSelectionParser();
        foreach ($parts as $part) {
            try {
                $fieldParser->parseFieldSelection($part);
            } catch (\Exception $e) {
                $errors[] = "Invalid field selection syntax in '{$part}': " . $e->getMessage();
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'depth' => $depth
        ];
    }

    /**
     * Get loading statistics
     * 
     * @return array Loading performance metrics
     */
    public function getLoadingStats(): array
    {
        return [
            'current_depth' => count($this->loadingStack),
            'loading_stack' => $this->loadingStack
        ];
    }

    /**
     * Reset loading state
     * 
     * @return void
     */
    public function reset(): void
    {
        $this->loadingStack = [];
    }
}
