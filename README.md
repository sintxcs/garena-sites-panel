# PanelWorks API Generator

## Introduction

PanelWorks is a simple, web-based panel for generating and managing temporary API links. It's built with PHP and doesn't require a database, using JSON files for data storage. The panel supports two user roles: Free and Premium, each with different permissions. It also includes an Admin panel for complete management of users, keys, and generated links.

This system is designed for scenarios where you need to provide temporary, sandboxed access to specific API files.

## Features

-   **User Roles:**
    -   **Admin:** Full control over the system. Can generate premium keys and manage all user-generated links.
    -   **Premium User:** Unlimited API link generation with custom expiration times (including lifetime).
    -   **Free User:** Can generate an API link once every 3 days, with a fixed expiration of 1 hour.
-   **Key-based Upgrades:** Free users can upgrade to Premium by redeeming a generated key.
-   **No Database Needed:** Uses a simple file-based system (`.json` files) for storing user, key, and link data.
-   **Self-Contained:** Everything is managed within a single PHP file.
-   **API Sandboxing:** Generates a unique directory for each API link to keep them isolated.
-   **Automatic Expiration Handling:** Premium subscriptions and lifetime links are automatically managed and revoked upon expiration.

## How It Works

1.  **Authentication:** Users can register and log in. There is a hardcoded `admin` user with a default password.
2.  **API Generation:**
    -   When a user generates an API link, the script creates a new, unique subdirectory inside the `/api/` folder.
    -   It then copies the source API files (`sinGarena-api.php`, `sinCodm-api.php`) into this new directory.
    -   Information about the link (owner, path, URL, expiration) is saved in `db/links.json`.
3.  **User Tiers:**
    -   **Free users** have a cooldown period (`FREE_USER_COOLDOWN`) between link generations.
    -   **Premium users** can generate links without any cooldown and can set custom expiration dates. They upgrade by redeeming a key.
4.  **Admin Management:**
    -   The admin can generate premium keys with specific durations (e.g., 3 days, 7 days, lifetime).
    -   The admin has a global view of all generated links and can delete any of them.
5.  **Verification:** A special function `sinVerifyPanelworks()` checks a remote server (`isnotsin.com`) to validate the panel's authenticity before performing critical actions.

## How to Install

1.  **Prerequisites:** You need a web server with PHP support.
2.  **Upload Files:** Upload the main panel file (e.g., `index.php`) and the source API files (`sinGarena-api.php`, `sinCodm-api.php`) to your web server.
3.  **Set Admin Password:** Open the panel file and change the `ADMIN_PASSWORD` constant to a strong, secret password:
    ```php
    define('ADMIN_PASSWORD', 'YourSecretPasswordHere');
    ```
4.  **Permissions:** Ensure your web server has permission to create directories and files. The script will automatically create the `db/` and `api/` directories.
5.  **Access:** Open the panel URL in your browser. Log in with `admin` and the password you set.

## Credits

This panel was crafted by **SIN**.

-   **Telegram:** [t.me/isnotsin](https://t.me/isnotsin)
-   **Website:** [isnotsin.com](https://isnotsin.com)

## Donation

If you find this project useful, you can show your support by buying me a coffee. Your donation helps in maintaining and developing more tools like this.

-   **GCash:** [Provide your GCash details here]
-   **PayPal:** [Provide your PayPal link here]
