<div align="center">
    <img src="doc/screenshots/worksphere_brand.png" width="400" alt="WorkSphere Brand">
    <h1>WorkSphere</h1>
    <p><strong>The Ultimate Enterprise "Super App" for Modern Business Management</strong></p>

<!-- Badges -->
<p>
    <a href="https://github.com/SummerRijndael/worksphere/stargazers">
        <img src="https://img.shields.io/github/stars/SummerRijndael/worksphere?style=for-the-badge&logo=github&color=FFD700" alt="Stars">
    </a>
    <a href="https://github.com/SummerRijndael/worksphere/blob/main/LICENSE">
        <img src="https://img.shields.io/github/license/SummerRijndael/worksphere?style=for-the-badge&logo=apache&color=007EC6" alt="License">
    </a>
    <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/Vue.js-3.5-4FC08D?style=for-the-badge&logo=vue.js&logoColor=white" alt="Vue 3">
    <br>
    <img src="https://img.shields.io/badge/OWASP%20ZAP-Tested-blue?style=for-the-badge&logo=owasp&logoColor=white" alt="OWASP ZAP Tested">
</p>

<!-- Support -->
<p>
    <a href='https://ko-fi.com/ryannolaso' target='_blank'>
        <img height='36' style='border:0px;height:36px;' src='https://storage.ko-fi.com/cdn/kofi2.png?v=3' border='0' alt='Buy Me a Coffee at ko-fi.com' />
    </a>
</p>
</div>

<br/>

<div align="center">
    <h3>🔴 Live Demo: <a href="https://link-technologies.info">link-technologies.info</a></h3>
</div>

<br/>

> [!WARNING]
> **BETA RELEASE NOTICE**<br>
> WorkSphere is currently in active **BETA** development. Features are subject to change, and you may encounter bugs or instability.<br>
> **NOT RECOMMENDED FOR PRODUCTION USE without extensive testing.**

---

## 🚀 Overview

**WorkSphere** is a comprehensive enterprise **Single Page Application (SPA)** built on **Laravel 12** and **Vue 3**. It functions as a centralized "Super App," seamlessly integrating multiple core business modules into a unified, real-time interface designed for efficiency and scalability.

## 🛠️ Technology Stack

| **Backend Core** | **Frontend Ecosystem** |
| :--- | :--- |
| ![Laravel](https://img.shields.io/badge/Laravel_12-FF2D20?style=flat-square&logo=laravel&logoColor=white) **Framework** | ![Vue.js](https://img.shields.io/badge/Vue.js_3.5-4FC08D?style=flat-square&logo=vue.js&logoColor=white) **Framework** |
| ![Reverb](https://img.shields.io/badge/Laravel_Reverb-FF2D20?style=flat-square&logo=laravel&logoColor=white) **Real-time (WebSocket)** | ![Pinia](https://img.shields.io/badge/Pinia-State_Management-yellow?style=flat-square&logo=vue.js&logoColor=white) **State Management** |
| ![MySQL](https://img.shields.io/badge/MySQL_8-4479A1?style=flat-square&logo=mysql&logoColor=white) **Database** | ![Tailwind](https://img.shields.io/badge/Tailwind_CSS_4.0-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white) **Styling** |
| ![Redis](https://img.shields.io/badge/Redis-Cache-DC382D?style=flat-square&logo=redis&logoColor=white) **Cache/Queue** | ![Vite](https://img.shields.io/badge/Vite_6.0-646CFF?style=flat-square&logo=vite&logoColor=white) **Build Tool** |
| ![Firewall](https://img.shields.io/badge/Laravel_Firewall-2.3-FF2D20?style=flat-square&logo=laravel&logoColor=white) **Security** | ![WebRTC](https://img.shields.io/badge/WebRTC-SimplePeer-333333?style=flat-square&logo=webrtc&logoColor=white) **Real-time Calls** |
| **Authentication:** Fortify + Sanctum + Socialite | **Key Libs:** Tiptap, FullCalendar, Chart.js, sdp-transform |

## ✨ Core Features & Modules


### 🔐 Authentication

*Secure login with social auth, multi-factor authentication (MFA), and role-based access.*
![Login Page](doc/screenshots/login_page.png)


### 📊 Dashboard

*A central hub for personal stats, pending tasks, and a high-level system overview.*
![Dashboard](doc/screenshots/dashboard.png)


### 💬 Real-time Communication

*Full-featured instant messaging and high-quality voice/video calls.*
- **Messaging**: Groups, file sharing, and typing indicators.
- **Video Calls**: 1:1 and Group video/audio calls with peer-to-peer WebRTC.
- **Hybrid SFU**: Scalable calls using Cloudflare Calls for groups > 6 participants.
- **Screen Sharing**: High-resolution screen sharing support for both 1:1 and group calls.
- **Global Notifications**: Receive call alerts anywhere in the application.

<div style="display: flex; gap: 10px; flex-wrap: wrap;">
    <img src="doc/screenshots/chat_mini.png" width="30%" alt="Chat Mini">
    <img src="doc/screenshots/chat_fullpage.png" width="30%" alt="Chat Full Page">
    <img src="doc/screenshots/video_call.png" width="30%" alt="Video Call">
</div>


### 📅 Project Management & Calendar

*Advanced task tracking, Kanban boards, and integrated scheduling.*
![Calendar](doc/screenshots/calendar.png)
![Team Details](doc/screenshots/team_details.png)
![Project Details](doc/screenshots/projects_details.png)
![Task Details](doc/screenshots/task_details.png)


### 🎫 Ticket System

*Internal support ticketing with automated workflows and status tracking.*
![Tickets List](doc/screenshots/tickets.png)
![Ticket Details](doc/screenshots/ticket_details.png)


### 📧 Unified Email Client

*IMAP/SMTP inbox with folder management and a rich text editor.*
![Email Client](doc/screenshots/email.png)


### 📈 Analytics

*Visual insights, reporting, and data visualization.*
![Analytics](doc/screenshots/analytics.png)


### 📚 Knowledge Base

*Knowledge management system with internal drafts and public article publishing.*
![KB Article List](doc/screenshots/kb_article_list.png)
![KB Article Editor](doc/screenshots/kb_article.png)
![Public Article](doc/screenshots/public_article_read.png)


### 📝 Personal Notes

*Markdown-supported personal note-taking app.*
![Notes](doc/screenshots/notes.png)


### 👥 Team & User Management

*Granular Role-Based Access Control (RBAC), team management, and user profiles.*
![User Manager](doc/screenshots/user_manager.png)
![User Details](doc/screenshots/user_details_manage.png)
![Roles & Permissions](doc/screenshots/roles_perms_manager.png)


### 🛡️ Security & Firewall

*Real-time threat monitoring, geographical attack visualization, and granular audit logging.*
- **Threat Map**: Live visualization of security incidents across the globe.
- **Firewall Integration**: Manage blocked/whitelisted IPs and monitor system-wide firewall logs.
- **Smart Audit Trails**: Context-aware logging that distinguishes between failure reasons (e.g., "User Not Found" vs. "Incorrect Password").
- **Time-Based Analytics**: Filter security data by arbitrary time periods (24h to 1y) to identify trends.
![Security Dashboard](doc/screenshots/security_dashboard.png)


### 🛠️ System Maintenance

*System health monitoring, queue management, and backups.*
![Maintenance](doc/screenshots/maintenance.png)

---

## ⚙️ Installation & Setup

### 1. Clone the Repository
```bash
git clone git@github.com:SummerRijndael/worksphere.git
cd worksphere
```

### 2. Install Dependencies
```bash
composer install
npm install
```

### 3. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```
> [!IMPORTANT]
> The `.env` file contains critical sensitive information. **Never** commit it to version control.

### 4. Configure External Services
Update `REVERB_*`, `GOOGLE_*`, `RECAPTCHA_*`, etc. in your `.env` file.

**Real-time (Laravel Reverb):**
```ini
REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http
```

Required for reliable video calls across different networks.
1. Go to **Cloudflare Dashboard** -> **Calls**.
2. Create a new **TURN App**.
3. Copy the **Key ID** and **API Token** to your `.env`:
```ini
TURN_KEY_ID="your-key-id"
TURN_KEY_API_TOKEN="your-api-token"
```

**Cloudflare SFU (Large Group Calls):**
Required for calls with more than 6 participants and advanced track management.
1. In the same **Cloudflare Calls** dashboard, create a new **App**.
2. Copy the **App ID** and **App Secret** to your `.env`:
```ini
CLOUDFLARE_APP_ID_SFU="your-app-id"
CLOUDFLARE_APP_SECRET_SFU="your-app-secret"
```

### 5. Initialize System
```bash
php artisan migrate --seed
php artisan storage:link
```

### 6. Run Application
```bash
npm run start-all
```
*Runs Laravel Serve, Reverb, Queue Worker, and Vite concurrently.*

---

## 🔑 Test Users

| Role | Email | Password |
| :--- | :--- | :--- |
| **Admin** | `admin@example.com` | `Xachgamb@01` |
| **User** | `test@example.com` | `Xachgamb@01` |

---

## 🏢 Roles & Permissions

WorkSphere uses a multi-layered authorization system:

- **Global Roles**: Powered by `Spatie/Permission` for application-wide access.
- **Team Roles**: Specific permissions within a team (Owner, Admin, Member, etc.).
- **Authorization Persona**: Centralized logic in the backend that resolves all permissions (Global + Team + Overrides) into a single context for efficient checking.
- **Permission Overrides**: Ability to grant or block specific permissions for a user, either permanently or temporarily.

## 🧭 Navigation & Security

- **Route Guards**: Frontend navigation is protected by Vue Router guards checking authentication, email verification, and specific permission requirements (`to.meta.permission`).
- **Permission-Scoped UI**: Components and actions are conditionally rendered based on the user's active permissions.
- **Secure APIs**: All backend endpoints are protected by Laravel Sanctum and fine-grained Policies/Gates.

## 🏛️ Architecture

*   **API-First**: Headless API (`routes/api.php`) protected by Sanctum.
*   **SPA Frontend**: Vue 3 app via single entry point.
*   **Real-time**: Private channel broadcasting via Laravel Reverb.
*   **Authorization**: Strict RBAC Policies.
*   **Service Pattern**: Business logic encapsulated in targeted Services (`app/Services`).

## 📄 License

This project is licensed under the **Apache License 2.0**.

&copy; 2026 WorkSphere. Internal Development.
