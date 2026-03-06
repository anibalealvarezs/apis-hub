# 🗄️ External Database Preparation

This guide outlines how to prepare a persistent external database (RDS, Managed MySQL) before deploying worker instances.

## 1. SQL Setup
Connect to your DB instance and run:
```sql
CREATE DATABASE apis_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Ensure permissions are granted to your user
```

## 2. Bootstrapping
Run the following utility once your credentials are in `project.yaml`:
```bash
sh bin/init-project.sh
```
