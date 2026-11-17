# Oxworks TV Display System (v3)

## Plesk Deployment
1. Extract the contents of this archive into your `media.oxworks.com.au` subdomain root directory (`index.php`, `admin.php`, `common.php` must sit side by side).
2. Ensure any default `index.html` file created by Plesk is deleted so `index.php` loads automatically.
3. Open `admin.php` in a text editor and change `$PASSWORD = 'Oxworks2026!';` on line 3 to your preferred password.
4. In Plesk **PHP Settings** for `media.oxworks.com.au`, increase `upload_max_filesize` and `post_max_size` to `128M` or `256M` to allow video uploads. The admin page shows the current limits under the upload box.
5. Make sure PHP can write to the subdomain root (first run creates `media/`) or create `media/` yourself with write permission for the PHP user.

`media/.htaccess` is created automatically. It blocks script execution and folder listings inside `media/`. Don't delete it.

## Usage
- **TV Display URL:** `https://media.oxworks.com.au`
- **Admin Dashboard URL:** `https://media.oxworks.com.au/admin.php`

## Upgrading from v2
Drop the new files over the old ones. The existing `media/` folder and `settings.json` are picked up as-is. Open TVs reload themselves automatically at the end of their current loop.
