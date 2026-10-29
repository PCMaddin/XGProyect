<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backups on another server (xgp:backup:run)
|--------------------------------------------------------------------------
|
| Every night the base database and each universe's database are dumped
| (mariadb-dump) into a restic repository on another server: encrypted with
| XGP_BACKUP_PASSWORD, kept by the rules below. The repository is any that
| restic knows, for example
|   s3:https://s3.eu-central-1.amazonaws.com/my-bucket/xgproyect
|     (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY; also Hetzner, Wasabi, MinIO)
|   b2:my-bucket:xgproyect          (B2_ACCOUNT_ID, B2_ACCOUNT_KEY)
|   sftp:backup@storage.example.com:/backups/xgproyect
| Empty: no backups of this kind. Without the password nothing can be
| restored: keep it apart from the server.
|
*/

return [
    'repository' => (string) env('XGP_BACKUP_REPOSITORY', ''),
    'password' => (string) env('XGP_BACKUP_PASSWORD', ''),
    // when the scheduler runs it (server time)
    'time' => (string) env('XGP_BACKUP_TIME', '03:17'),
    // snapshots kept per database
    'keep_daily' => (int) env('XGP_BACKUP_KEEP_DAILY', 7),
    'keep_weekly' => (int) env('XGP_BACKUP_KEEP_WEEKLY', 5),
    'keep_monthly' => (int) env('XGP_BACKUP_KEEP_MONTHLY', 12),
    // the programs
    'restic' => (string) env('XGP_BACKUP_RESTIC', 'restic'),
    'dump' => (string) env('XGP_BACKUP_DUMP', 'mariadb-dump'),
    'client' => (string) env('XGP_BACKUP_CLIENT', 'mariadb'),
];
