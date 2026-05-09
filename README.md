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

Base URL: `http://<host>:8000/api`

### 1. Data Al-Qur'an (Public)
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `GET` | `/api/chapters` | Ambil daftar semua surah (1-114). |
| `GET` | `/api/surah/{id}` | Ambil semua ayat dalam surah tertentu. |
| `GET` | `/api/audio/{reciter}/{chapter}/{verse}` | Ambil URL audio spesifik untuk satu ayat. |

### 2. Authentication
| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `POST` | `/api/auth/register` | Registrasi akun baru + token Sanctum. |
| `POST` | `/api/auth/login` | Login + token Sanctum. |
| `GET` | `/api/auth/me` | Ambil profil user login. (Auth) |
| `POST` | `/api/auth/logout` | Logout token saat ini. (Auth) |

### 3. Game System
*Endpoint di bawah (kecuali leaderboard) wajib menyertakan header: `Authorization: Bearer <your_token>`*

| Method | Endpoint | Deskripsi |
| :--- | :--- | :--- |
| `POST` | `/api/game/start` | Mulai sesi kuis (mode all_juz / juz / surah), jumlah soal 5/10/20, opsi 4 pilihan. |
| `POST` | `/api/game/submit` | Submit jawaban per soal. Skor, combo, streak dihitung server-side (anti-cheat). |
| `GET` | `/api/game/leaderboard` | Ambil daftar 50 pemain dengan poin tertinggi. |

#### Contoh Request `POST /api/game/start`:
```json
{
   "mode": "surah",
   "surah_ids": [1, 2, 3],
   "jumlah_soal": 10,
   "reciter_id": 7
}
```

Pilihan mode:
- `all_juz` = acak dari seluruh Quran
- `juz` = acak dari juz tertentu (wajib `juz` 1-30)
- `surah` = acak dari daftar beberapa surah (`surah_ids`)

#### Contoh Request `POST /api/game/submit`:
```json
{
   "session_id": 12,
   "answers": [
      {"question_id": 1001, "selected_verse_id": 5012},
      {"question_id": 1002, "selected_verse_id": 6011}
   ]
}
```

## 🎮 Mekanik Game
1. **Mode Soal:** all_juz, per-juz, atau multi-surah acak.
2. **Progression:** untuk mode surah, surah berikutnya terkunci sampai surah sebelumnya lulus (perfect).
3. **Scoring Server-side:** client hanya kirim jawaban; score/combo/streak dihitung di backend.
4. **Leaderboard:** skor bersifat akumulatif lintas sesi.

## 📁 Struktur Penting
- `app/Http/Controllers/API/GameController.php`: Logika utama game.
- `app/Models/UserProgress.php`: Menyimpan status kelulusan surah per user.
- `app/Models/Leaderboard.php`: Menyimpan total skor dan rekor user.
- `routes/api.php`: Daftar seluruh endpoint yang tersedia.

---
Dikembangkan untuk tugas Mobile Programming - **Aplikasi Sambung Ayat**.
