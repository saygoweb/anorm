#!/bin/bash

# Test script to verify git completion is working

echo "Testing git completion..."

# Source bash completion if not already loaded
if ! complete -p git &>/dev/null; then
    echo "Loading bash completion..."
    source /usr/share/bash-completion/bash_completion
fi

# Check if git completion is loaded
if complete -p git &>/dev/null; then
    echo "✅ Git completion is loaded!"
    echo "Completion command: $(complete -p git)"
else
    echo "❌ Git completion is NOT loaded"
    exit 1
fi

# Test if we can get completions for git commands
echo ""
echo "Testing git command completions..."

# Simulate what happens when you type "git " and press tab
COMP_WORDS=(git "")
COMP_CWORD=1
COMP_LINE="git "
COMP_POINT=4

# Call the completion function
__git_wrap__git_main

if [ ${#COMPREPLY[@]} -gt 0 ]; then
    echo "✅ Git command completion working! Found ${#COMPREPLY[@]} completions"
    echo "Sample completions: ${COMPREPLY[@]:0:10}"
else
    echo "❌ Git command completion not working"
fi

echo ""
echo "Testing git branch completions..."

# Test branch completion for "git checkout "
COMP_WORDS=(git checkout "")
COMP_CWORD=2
COMP_LINE="git checkout "
COMP_POINT=13

# Call the completion function
__git_wrap__git_main

if [ ${#COMPREPLY[@]} -gt 0 ]; then
    echo "✅ Git branch completion working! Found ${#COMPREPLY[@]} completions"
    echo "Available branches: ${COMPREPLY[@]}"
else
    echo "ℹ️  No branch completions (this is normal if no branches match)"
fi

echo ""
echo "Git completion test complete!"
