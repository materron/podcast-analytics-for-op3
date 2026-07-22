# Generates .pot file from source
# Requires gettext

Get-ChildItem -Recurse -Path (Split-Path $PSScriptRoot -Parent) -Filter "*.php" | Select-Object -ExpandProperty FullName > php_files.txt
xgettext --default-domain="podcast-analytics-for-op3" -o "languages/podcast-analytics-for-op3.pot" --language="PHP" --keyword=__ --keyword=esc_html__ --keyword=esc_html_e --from-code="UTF-8" --files-from="php_files.txt"

Remove-Item php_files.txt
