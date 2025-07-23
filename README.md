# Trackie.in – Modern Habit, Goal, and Productivity Tracker

A comprehensive, interactive, and visually stunning productivity platform built with PHP, MySQL, and modern web technologies. Track your habits, goals, todos, routines, and study plans—all in one place.

---

## 🚀 Features

- **Dashboard**: Unified overview with calendar, weather, analytics, and quick access to all modules.
- **Todos**: Add, edit, complete, and filter todos. AJAX-powered for instant feedback. Progress bars and stats.
- **Habits**: Track daily/weekly habits, log completions, view streaks, and see weekly stats. Add/edit/delete with modals and AJAX.
- **Goals**: Set, track, and update personal goals. Progress bars, completion badges, deadlines, and inline progress updates.
- **Routines**: Organize routines by time of day (morning, afternoon, evening, night). Grouped display and card-based UI.
- **Study Plan**: Manage study tasks by subject, type, and priority. Filter and view by date and subject.
- **Analytics**: Visualize progress across todos, habits, goals, and routines. Weekly habit performance, recent activity, and completion stats.
- **Calendar**: Monthly view with todos and study tasks. Badges and tooltips for days with tasks. Month navigation.
- **Profile**: View and update user info, email, password, and profile picture. Instant profile picture upload.
- **Spotify Integration**: Connect your Spotify account, display your profile and top tracks on the dashboard.
- **Authentication**: Secure login, registration, and logout flows. Session management and user feedback.
- **Custom Error Pages**: 404 and 500 error pages for a polished experience.
- **Responsive & Modern UI**: Card-based, mobile-friendly, and beautiful. Dark mode and luxury design system.

---

## 📁 Project Structure

```
Trackie/
├── assets/
│   ├── css/style.css           # Modern luxury design system
│   ├── js/app.js              # Interactive JS for modals, AJAX, etc.
│   └── images/                # User avatars, logos, and icons
├── config/database.php        # Database connection and helpers
├── includes/
│   ├── header.php             # Shared header (dynamic title, emoji, user menu)
│   ├── sidebar.php            # Sidebar navigation
│   ├── footer.php             # Footer
│   └── functions.php          # Utility functions (AJAX, flash, etc.)
├── dashboard.php              # Main dashboard (calendar, weather, analytics, goals, etc.)
├── pages/
│   ├── todos.php, todo_manager.php, new_todo.php
│   ├── habits_simple.php, habits.php
│   ├── goals_simple.php, goals.php
│   ├── routine_simple.php, routine.php
│   ├── study_plan.php
│   ├── analytics_simple.php, analytics.php
│   ├── calendar.php
│   ├── profile.php
│   ├── spotify_auth.php, spotify_api.php
│   ├── login.php, register.php, logout.php
│   ├── 404.php, 500.php
│   └── ...
├── trackie_in.sql             # Database schema
├── README.md                  # This file
└── ...
```

---

## 🛠️ Key Technologies
- **PHP 7.4+** (backend, AJAX endpoints)
- **MySQL** (database)
- **Tailwind CSS** + custom CSS (UI)
- **Font Awesome** (icons)
- **JavaScript** (AJAX, modals, interactivity)
- **Spotify Web API** (music integration)
- **OpenWeatherMap API** (weather integration)

---

## 🧩 Main Modules & Pages

### Dashboard
- Unified overview: calendar, weather, analytics, quick stats, and top goals/habits.
- Dynamic cards and modals for quick actions.

### Todos
- Add, edit, complete, and delete todos.
- Filter by status (all, pending, completed, overdue, today) and search.
- AJAX for instant updates. Progress bars and stats.

### Habits
- Track daily/weekly habits, log completions, view streaks, and see weekly stats.
- Add/edit/delete with modals and AJAX. Progress bars and analytics.

### Goals
- Set, track, and update personal goals. Progress bars, completion badges, deadlines.
- Add/edit/delete with modals and AJAX. Inline progress updates.

### Routines
- Organize routines by time of day. Add/edit/delete routines. Grouped display.

### Study Plan
- Manage study tasks by subject, type, and priority. Filter and view by date and subject.

### Analytics
- Visualize progress across todos, habits, goals, and routines. Weekly habit performance, recent activity, and completion stats.

### Calendar
- Monthly view with todos and study tasks. Badges and tooltips for days with tasks. Month navigation.

### Profile
- View and update user info, email, password, and profile picture. Instant profile picture upload.

### Spotify Integration
- Connect your Spotify account via OAuth. Display your profile and top tracks on the dashboard.

### Authentication
- Secure login, registration, and logout flows. Session management and user feedback.

### Error Pages
- Custom 404 and 500 error pages for a polished experience.

---

## 🗄️ Database Schema

- **users**: User accounts and profiles
- **habits**: User-defined habits
- **logs**: Habit completion logs
- **routines**: Scheduled routines and tasks
- **todos**: Todo items
- **goals**: User goals and progress
- **study_plan**: Study tasks and plans

See `trackie_in.sql` for full schema and sample data.

---

## 🚦 Setup & Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/Trackie.git
   cd Trackie
   ```
2. **Set up the database**
   - Create a MySQL database (e.g., `trackie`)
   - Import the `trackie_in.sql` file
   - Update credentials in `config/database.php`
3. **Configure your web server**
   - Point your web server to the project directory
   - Ensure PHP 7.4+ is installed
   - Enable required PHP extensions (PDO, MySQL)
4. **Set up file permissions**
   ```bash
   chmod 755 -R .
   chmod 777 assets/images/  # For profile picture uploads
   ```
5. **Configure API Keys**
   - [Spotify Developer Dashboard](https://developer.spotify.com/dashboard/applications) for Spotify integration
   - [OpenWeatherMap](https://openweathermap.org/api) for weather (add your API key in the relevant PHP file)

---

## 🧑‍💻 Contributing

Pull requests are welcome! For major changes, please open an issue first to discuss what you would like to change.

---

## 📄 License

[MIT](LICENSE)

---

## 💡 Credits
- Inspired by modern productivity apps and luxury UI design.
- Built with ❤️ by [Your Name] and contributors.


 
