# Server media upload settings

For property photo uploads and server-side compression, production PHP must allow large incoming files before the app can optimize them.

Required PHP values:

```ini
file_uploads = On
upload_max_filesize = 50M
post_max_size = 220M
max_file_uploads = 50
memory_limit = 512M
max_input_time = 120
max_execution_time = 120
extension=fileinfo
extension=gd
```

If the hosting uses PHP-FPM/shared hosting, `public/.user.ini` already contains the per-directory upload limits. Extensions like `gd` and `fileinfo` usually must be enabled in the hosting panel or the global PHP configuration.

If the server uses nginx, also set:

```nginx
client_max_body_size 220m;
```

Restart or reload PHP-FPM/Apache/nginx after changing server-level settings.
