import os
import shutil
import subprocess

php_dir = r"C:\Users\Kian\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe"
ini_path = os.path.join(php_dir, "php.ini")
dev_ini = os.path.join(php_dir, "php.ini-development")

if not os.path.exists(ini_path) and os.path.exists(dev_ini):
    shutil.copyfile(dev_ini, ini_path)

with open(ini_path, "r", encoding="utf-8", errors="ignore") as f:
    ini = f.read()

ini = ini.replace(';extension_dir = "ext"', 'extension_dir = "ext"')
ini = ini.replace(';extension=curl', 'extension=curl')
ini = ini.replace(';extension=pdo_mysql', 'extension=pdo_mysql')
ini = ini.replace(';extension=mysqli', 'extension=mysqli')
ini = ini.replace(';extension=mbstring', 'extension=mbstring')
ini = ini.replace(';extension=openssl', 'extension=openssl')

with open(ini_path, "w", encoding="utf-8") as f:
    f.write(ini)

php_exe = os.path.join(php_dir, "php.exe")
print("PHP version:")
subprocess.run([php_exe, "-v"])
