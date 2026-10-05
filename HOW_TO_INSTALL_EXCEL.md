# How to Install the Excel Import Feature

## Requirement: Composer (to install PhpSpreadsheet)

### Step 1 — Download Composer
1. Go to https://getcomposer.org/download/
2. Download `composer.phar` (or install the Windows installer)
3. Place `composer.phar` inside the `gnc-osa-portal/` folder

### Step 2 — Install PhpSpreadsheet
Open Command Prompt / Terminal inside the `gnc-osa-portal/` folder:

```bash
# If you are using XAMPP on Windows:
C:\xampp\php\php.exe composer.phar install

# If you are on Linux/Mac:
php composer.phar install
```

Wait until it finishes — the `vendor/` folder will appear.

### Step 3 — Done!
The following are now ready:
- `admin/candidates.php` — now has an "Import Excel" button
- `admin/import-candidates.php` — upload handler
- Template download — available inside the import modal

## Excel Template Format
| student_id   | position      |
|-------------|---------------|
| 2021-00123  | President     |
| 2021-00456  | Vice President|
| 2022-00789  | Secretary     |

**IMPORTANT:**
- The `student_id` must exactly match the one in the database
- The `position` must exactly match the position title of the election (case-sensitive)
