# Quran Sambung Ayat - Backend API

Backend ini adalah layanan REST API murni yang dibangun dengan Laravel untuk mendukung aplikasi mobile **Sambung Ayat**. Project ini telah dibersihkan dari fitur frontend, chatbot, dan tafsir untuk fokus sepenuhnya pada kebutuhan gamifikasi hafalan Al-Qur'an.

## 🚀 Fitur Utama
- **Data Al-Qur'an:** Akses daftar surah, ayat, dan audio per ayat.
- **Sistem Gamifikasi:** Poin, combo, streak, dan leaderboard.
- **Strict Progression:** User harus menyelesaikan surah sebelumnya (Lulus) untuk membuka surah berikutnya (kecuali Al-Fatihah).
- **Security:** Autentikasi menggunakan Laravel Sanctum.
- **CORS Enabled:** Siap dikonsumsi oleh aplikasi Android (Kotlin/Compose).

## 🛠️ Persyaratan Sistem
- PHP >= 8.2
- Composer
- MySQL (Direkomendasikan menggunakan Docker)
- Extensions PHP: `pdo_mysql`, `mysqli`, `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `tokenizer`, `xml`

## ⚙️ Instalasi & Setup Lokal
1. **Clone repository & masuk ke folder:**
   ```bash
   cd quran_beckend
   ```
2. **Install dependencies:**
   ```bash
   composer install --ignore-platform-reqs
   ```
3. **Konfigurasi Environment:**
   Salin `.env.example` menjadi `.env` dan sesuaikan pengaturan database Anda:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
4. **Jalankan Migrasi Database:**
   ```bash
   php artisan migrate
   ```
5. **Jalankan Server:**
   ```bash
   php artisan serve --host=0.0.0.0
   ```
   *(Gunakan `--host=0.0.0.0` agar bisa diakses dari perangkat Android di jaringan yang sama)*

## 📖 Dokumentasi API

### 1. Data Al-Qur'an (Public)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/chapters` | Ambil daftar semua surah (1-114). |
| `GET` | `/api/surah/{id}` | Ambil semua ayat dalam surah tertentu. |
| `GET` | `/api/audio/{reciter}/{chapter}/{verse}` | Ambil URL audio spesifik untuk satu ayat. |

### 2. Game System (Auth Protected)
*Wajib menyertakan header: `Authorization: Bearer <your_token>`*

| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `POST` | `/api/game/start` | Mulai sesi kuis. Mengembalikan ayat acak beserta 3 pilihan pengecoh. |
| `POST` | `/api/game/submit` | Kirim skor setelah menyelesaikan kuis. Jika `is_perfect: true`, status level surah menjadi Lulus. |
| `GET` | `/api/game/leaderboard` | Ambil daftar 50 pemain dengan poin tertinggi. |

#### Contoh Request `POST /api/game/start`:
```json
{
    "chapter_id": 1,
    "jumlah_soal": 5
}
```

#### Contoh Request `POST /api/game/submit`:
```json
{
    "score": 1200,
    "streak": 7,
    "combo": 3,
    "surah_id": 1,
    "is_perfect": true
}
```

## 🎮 Mekanik Game
1. **Pengecekkan Progres:** Saat memanggil `/api/game/start`, sistem akan mengecek apakah surah sebelumnya sudah lulus (`is_passed`).
2. **Pengecoh (Options):** API secara otomatis mencarikan 3 ayat lain dari surah/juz yang sama sebagai pilihan jawaban salah di Android.
3. **Leaderboard:** Skor bersifat akumulatif. Semakin sering bermain dan benar, semakin tinggi peringkat user.

## 📁 Struktur Penting
- `app/Http/Controllers/API/GameController.php`: Logika utama game.
- `app/Models/UserProgress.php`: Menyimpan status kelulusan surah per user.
- `app/Models/Leaderboard.php`: Menyimpan total skor dan rekor user.
- `routes/api.php`: Daftar seluruh endpoint yang tersedia.

---
Dikembangkan untuk tugas Mobile Programming - **Aplikasi Sambung Ayat**.
