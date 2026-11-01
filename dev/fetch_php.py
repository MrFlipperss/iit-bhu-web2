import os
import sys
import urllib.request
import zipfile

url = "https://windows.php.net/downloads/releases/archives/php-8.2.35-Win32-vs16-x64.zip"
target_dir = r"C:\Users\Kian\php82"
zip_path = r"C:\Users\Kian\Downloads\php-8.2.35.zip"

os.makedirs(target_dir, exist_ok=True)
print(f"Downloading {url} ...")

def reporthook(blocknum, blocksize, totalsize):
    readsofar = blocknum * blocksize
    if totalsize > 0:
        percent = readsofar * 1e2 / totalsize
        if blocknum % 1000 == 0:
            print(f"Downloaded {readsofar // (1024*1024)}MB / {totalsize // (1024*1024)}MB ({percent:.1f}%)")

urllib.request.urlretrieve(url, zip_path, reporthook)
print("Download finished. Extracting...")

with zipfile.ZipFile(zip_path, 'r') as zip_ref:
    zip_ref.extractall(target_dir)

if os.path.exists(zip_path):
    os.remove(zip_path)

print("Configuring php.ini...")
dev_ini = os.path.join(target_dir, "php.ini-development")
target_ini = os.path.join(target_dir, "php.ini")

with open(dev_ini, "r", encoding="utf-8", errors="ignore") as f:
    ini = f.read()

ini = ini.replace(';extension_dir = "ext"', 'extension_dir = "ext"')
ini = ini.replace(';extension=curl', 'extension=curl')
ini = ini.replace(';extension=pdo_mysql', 'extension=pdo_mysql')
ini = ini.replace(';extension=mysqli', 'extension=mysqli')
ini = ini.replace(';extension=mbstring', 'extension=mbstring')
ini = ini.replace(';extension=openssl', 'extension=openssl')

with open(target_ini, "w", encoding="utf-8") as f:
    f.write(ini)

print("PHP 8.2 setup successfully completed!")
