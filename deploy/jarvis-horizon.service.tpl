[Unit]
Description=JARVIS ONE queue supervisor (Laravel Horizon)
After=network.target docker.service

[Service]
User=jarvis
Group=www-data
WorkingDirectory=@ROOT@
ExecStart=/usr/bin/php artisan horizon
ExecStop=/usr/bin/php artisan horizon:terminate
Restart=always
RestartSec=5
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
