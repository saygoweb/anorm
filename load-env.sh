#!/bin/bash
# Load environment variables

if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
    echo "Environment variables loaded from .env"
    echo "DB_HOST=$DB_HOST"
    echo "DB_NAME=$DB_NAME"
    echo "DB_USER=$DB_USER"
else
    echo "No .env file found"
fi
