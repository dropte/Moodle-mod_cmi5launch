#!/bin/bash

# Sync plugin to Kubernetes pod for testing
# Usage: ./sync-to-k8s.sh <pod-name>

if [ -z "$1" ]; then
    echo "Usage: $0 <pod-name>"
    echo "Example: $0 moodle-7c5b8d9f4-xyz12"
    echo ""
    echo "Find your pod name with: kubectl get pods"
    exit 1
fi

POD_NAME=$1
PLUGIN_PATH="/var/www/html/mod/cmi5launch"

echo "🚀 Syncing plugin to pod: $POD_NAME"

# Copy modified files
echo "📁 Copying PHP files..."
kubectl cp view.php $POD_NAME:$PLUGIN_PATH/view.php
kubectl cp AUview.php $POD_NAME:$PLUGIN_PATH/AUview.php
kubectl cp settings.php $POD_NAME:$PLUGIN_PATH/settings.php
kubectl cp lib.php $POD_NAME:$PLUGIN_PATH/lib.php
kubectl cp locallib.php $POD_NAME:$PLUGIN_PATH/locallib.php
kubectl cp styles.css $POD_NAME:$PLUGIN_PATH/styles.css

echo "📁 Copying language files..."
kubectl cp lang/en/cmi5launch.php $POD_NAME:$PLUGIN_PATH/lang/en/cmi5launch.php

echo "📁 Copying icon files..."
kubectl cp pix/monologo.svg $POD_NAME:$PLUGIN_PATH/pix/monologo.svg

echo "🔧 Fixing permissions..."
kubectl exec $POD_NAME -- chown -R www-data:www-data $PLUGIN_PATH
kubectl exec $POD_NAME -- chmod -R 755 $PLUGIN_PATH

echo "🧹 Clearing Moodle cache..."
kubectl exec $POD_NAME -- php /var/www/html/admin/cli/purge_caches.php 2>/dev/null || echo "⚠️  Cache purge failed - you may need to do this manually"

echo "✅ Sync complete! Test your changes in Moodle now."
echo "💡 Tip: Run 'kubectl logs $POD_NAME' to see PHP errors"
