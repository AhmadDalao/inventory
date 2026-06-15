# Inventory HQ

Simple inventory tracking app built with plain PHP and MySQL.

## What it does

- Login with role-based access
- Owner account plus admin management
- CRUD for inventory items
- Current stock tracking per item
- Movement log for usage, restock, and adjustments
- Dashboard metrics for stock, low inventory, value, and recent activity
- CSV export for items and movement history

## Local run

1. Copy `.env.example` to `.env`
2. Fill in your MySQL credentials
3. Start the dev server:

```bash
php -S 127.0.0.1:8080 router.php
```

4. Open `http://127.0.0.1:8080`
5. Complete setup to create the first owner account

## Deploy

1. Upload the project to your web root
2. Add a real `.env` file on the server
3. Make sure the domain points to this folder
4. Open the app and finish setup

Apache rewrite rules live in `.htaccess`, so shared hosting is fine.
