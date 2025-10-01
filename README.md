# Affiliate Writer Project – Dev Notes

## ✅ Environment Setup
- PHP 8.2 (LocalWP / Lightning Services)
- Slim Framework
- Illuminate/Database (Eloquent ORM)
- Dotenv for env config

## 📂 Folder Structure
```
affiliated-writer-new/
├── app/
│   ├── routes.php
│   ├── middleware.php
│   └── .env   ← এখানে মূল env config ছিল
├── public/
│   └── index.php
├── vendor/
├── worker.php  ← queue worker
└── README.md   ← এই documentation
```

## ⚙️ Environment (.env)
```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_HOST=127.0.0.1
DB_PORT=10005
DB_NAME=local
DB_USER=root
DB_PASS=root

JWT_SECRET=my_super_secret_key_12345
```

## 🗄 Database Schema
```sql
-- Users
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100),
  email VARCHAR(150) UNIQUE,
  password VARCHAR(255),
  credits INT DEFAULT 10,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Articles
CREATE TABLE articles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED,
  title VARCHAR(255),
  keyword TEXT,
  slug VARCHAR(255),
  status ENUM('draft','queued','processing','completed') DEFAULT 'draft',
  html LONGTEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Jobs
CREATE TABLE jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  article_id INT UNSIGNED,
  status ENUM('queued','processing','done','failed') DEFAULT 'queued',
  error TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (article_id) REFERENCES articles(id)
);

-- Prompts
CREATE TABLE prompts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(50),
  template TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## 🚀 Running Slim Server
```powershell
cd "C:\Users\RJ COMPUTER\Local Sites\affiliated-writer-new"
php -S localhost:8080 -t public
```

## ⚙️ Running Worker
```powershell
php worker.php
```
- Worker queue থেকে `jobs` process করে → `articles` টেবিলে html save করে।  

## 🔑 API Flow
1. **Register** → `/api/register`
2. **Login** → `/api/login` → token
3. **Generate Article** → `POST /api/article/generate`  
   Example body:
   ```json
   {
     "title": "How to Use Slim Framework",
     "keyword": "php, slim, tutorial"
   }
   ```
4. **Render Article** → `/api/article/render/{id}`

## 📝 Notes
- DB port: `10005` (LocalWP MySQL)
- যদি worker এ `.env` না পাওয়া যায় → `.env` ফাইল project root এ copy করতে হবে।
- সব changes future reference এর জন্য এখানে নোট করা হয়েছে।

---
