# Backups (PHP version)

Keep a copy of the database and the uploaded files, at least after every race day.

- **Database:** phpMyAdmin → the `avilonx` database → **Export** → **Go**. Keep the
  `.sql` file somewhere else (Google Drive, a USB stick). To restore: create an
  empty database and **Import** that file.
- **Files:** copy the `uploads` folder (bird photos, clocking videos, club logos),
  or the folder set as `storage_dir` in `api/config.php`.
- **Hosting:** most panels have automatic daily backups; turn them on, and still
  download a copy now and then.
