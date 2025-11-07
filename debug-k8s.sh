#!/bin/bash

# Debug script for Kubernetes deployment issues
if [ -z "$1" ]; then
    echo "Usage: $0 <pod-name>"
    exit 1
fi

POD_NAME=$1
PLUGIN_PATH="/var/www/html/mod/cmi5launch"

echo "🔍 Checking plugin files in pod: $POD_NAME"
echo "================================================"

echo -e "\n📁 Checking if plugin directory exists..."
kubectl exec $POD_NAME -- ls -la $PLUGIN_PATH 2>&1 | head -20

echo -e "\n📄 Checking for AUview.php..."
kubectl exec $POD_NAME -- ls -la $PLUGIN_PATH/AUview.php 2>&1

echo -e "\n🔐 Checking permissions..."
kubectl exec $POD_NAME -- stat $PLUGIN_PATH/AUview.php 2>&1

echo -e "\n🌐 Checking web server config..."
kubectl exec $POD_NAME -- ls -la /var/www/html/mod/ 2>&1 | grep cmi5

echo -e "\n📝 Checking PHP errors (last 20 lines)..."
kubectl logs $POD_NAME --tail=20 2>&1 | grep -i error || echo "No recent errors found"

echo -e "\n✅ Debug complete!"
echo "================================================"
echo "If AUview.php is missing, run: ./sync-to-k8s.sh $POD_NAME"
