# Gider Takip Sistemi

Bu proje basit bir kâr/zarar hesaplama CRM uygulamasıdır. PHP tabanlıdır ve yapay zeka destekli tahmini gelir/gider özelliği içerir.

## Kurulum
1. Depoyu klonlayın.
2. Proje dizininde bir PHP yerel sunucusu başlatın:
   ```bash
   php -S localhost:8000
   ```
3. Tarayıcınızda `http://localhost:8000` adresine gidin.

## Giriş Bilgileri
- **Kullanıcı adı:** `admin`
- **Şifre:** `password`

İlk çalıştırmada `data.json` dosyası otomatik oluşur ve kullanıcı bilgisi bu şifreyle kaydedilir.

## Özellikler
- Gelir ve gider kalemleri ekleyerek anlık kâr/zarar hesabı.
- Basit hareketli ortalama yöntemiyle bir sonraki kayda yönelik tahmin (yapay zeka özelliği).
- Oturum sistemi ve admin paneli.

Uygulama örnek amaçlıdır ve geliştirilmeye açıktır.
