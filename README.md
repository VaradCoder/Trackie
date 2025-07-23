# Trackie.in - Habit Tracking Application

A comprehensive habit tracking and goal management application built with PHP, MySQL, and modern web technologies.

## 🚀 Features

- **Dashboard**: Interactive dashboard with calendar, weather, and analytics
- **Habit Tracking**: Create and monitor daily/weekly habits
- **Goal Management**: Set and track progress towards goals
- **Routine Management**: Schedule and manage daily routines
- **Analytics**: Detailed progress tracking and insights
- **User Management**: Secure authentication and user profiles
- **Mobile Responsive**: Works seamlessly on all devices

## 📁 Project Structure

```
Trackie/
├── config/
│   └── database.php          # Database configuration and helper functions
├── includes/
│   ├── functions.php         # Common utility functions
│   ├── header.php           # HTML header with meta tags and CSS
│   ├── navbar.php           # Navigation bar component
│   ├── sidebar.php          # Sidebar navigation component
│   └── footer.php           # Footer component
├── dashboard.php            # Main dashboard page
├── test_save_task.php       # API testing utility
├── trackie_in.sql          # Database schema and sample data
└── README.md               # This file
```

## 🛠️ Recent Fixes and Improvements

### 1. **Complete Design Overhaul** - Luxury Dark Theme
- ✅ Implemented black, white, and cream color scheme with gold accents
- ✅ Added glassmorphism effects and luxury design elements
- ✅ Created comprehensive CSS with animations and transitions
- ✅ Implemented responsive design for all devices
- ✅ Added dark mode support and user preferences

### 2. **Dashboard.php** - Complete Restructure
- ✅ Added proper session handling and authentication
- ✅ Fixed HTML structure with proper DOCTYPE, head, and body tags
- ✅ Added missing Font Awesome CDN link
- ✅ Fixed include paths (removed incorrect `../includes/` references)
- ✅ Added proper closing tags and structure
- ✅ Improved JavaScript functionality for sidebar toggle
- ✅ Enhanced calendar and todo list interactions
- ✅ Implemented luxury design theme

### 3. **Header.php** - Cleanup and Optimization
- ✅ Removed duplicate Tailwind CSS and Google Fonts links
- ✅ Reorganized link order for better performance
- ✅ Added proper Font Awesome integration
- ✅ Improved meta tag structure
- ✅ Added dynamic asset path resolution

### 4. **Sidebar.php** - Enhanced Navigation
- ✅ Improved mobile menu functionality
- ✅ Added proper accessibility attributes
- ✅ Enhanced click-outside-to-close functionality
- ✅ Better icon transitions and user experience
- ✅ Implemented luxury design theme
- ✅ Added proper navigation links

### 5. **Footer.php** - Structure Fixes
- ✅ Added proper closing body and html tags
- ✅ Enhanced content with additional information
- ✅ Better semantic structure
- ✅ Implemented luxury design theme

### 6. **Database Configuration** - Centralized Management
- ✅ Created `config/database.php` with PDO connection
- ✅ Added helper functions for common database operations
- ✅ Implemented proper error handling
- ✅ Added prepared statement support for security
- ✅ Fixed database name configuration

### 7. **Utility Functions** - Enhanced Functionality
- ✅ Created `includes/functions.php` with common utilities
- ✅ Added input sanitization and validation functions
- ✅ Implemented secure password hashing
- ✅ Added session management helpers
- ✅ Created file upload validation
- ✅ Added activity logging functionality

### 8. **JavaScript Enhancement** - Interactive Features
- ✅ Created comprehensive `assets/js/app.js` with modern functionality
- ✅ Added toast notification system
- ✅ Implemented form validation and AJAX handling
- ✅ Added dark mode toggle and user preferences
- ✅ Enhanced sidebar and navigation interactions
- ✅ Added smooth animations and transitions

### 9. **Security and Deployment** - Production Ready
- ✅ Created `.htaccess` with security headers and optimization
- ✅ Added comprehensive deployment guide
- ✅ Implemented error pages (404, 500)
- ✅ Added security hardening recommendations
- ✅ Created connection test script
- ✅ Added proper file permissions and directory structure

### 10. **Asset Management** - Complete Structure
- ✅ Created `assets/css/style.css` with luxury design system
- ✅ Added `assets/js/app.js` with modern JavaScript functionality
- ✅ Organized images and static assets
- ✅ Implemented proper asset loading and caching

## 🗄️ Database Schema

The application uses MySQL with the following main tables:

- **users**: User accounts and profiles
- **habits**: User-defined habits
- **logs**: Habit completion logs
- **routines**: Scheduled routines and tasks
- **todos**: Todo items
- **goals**: User goals and progress

## 🚀 Installation

1. **Clone the repository**
   ```bash
   git clone git@github.com:VaradCoder/Trackie.git
   cd Track
   ```

2. **Set up the database**
   - Create a MySQL database named `trackie.in`
   - Import the `trackie_in.sql` file
   - Update database credentials in `config/database.php`

3. **Configure your web server**
   - Point your web server to the project directory
   - Ensure PHP 7.4+ is installed
   - Enable required PHP extensions (PDO, MySQL)

4. **Set up file permissions**
   ```bash
   chmod 755 -R .
   chmod 777 uploads/  # If using file uploads
   ```

## 🔧 Configuration

### Database Configuration
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'trackie.in');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### API Configuration
Update API endpoints in relevant files:
```php
$api_url = 'http://your-domain.com/api/';
```

## 🎨 Styling

The application uses:
- **Tailwind CSS** for styling
- **Font Awesome** for icons
- **Google Fonts** (Comic Neue, Bangers) for typography
- **Custom CSS** for additional styling

## 🔒 Security Features

- ✅ Session-based authentication
- ✅ Password hashing with bcrypt
- ✅ Input sanitization and validation
- ✅ Prepared statements for database queries
- ✅ CSRF protection (recommended to implement)
- ✅ XSS prevention through output escaping

## 📱 Responsive Design

The application is fully responsive and works on:
- Desktop computers
- Tablets
- Mobile phones
- All modern browsers

## 🧪 Testing

Use `test_save_task.php` to test API functionality:
```bash
php test_save_task.php
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License.

## 🆘 Support

For support and questions:
- Create an issue in the repository
- Contact the development team
- Check the documentation

## 🔄 Version History

### v1.1.0 (Current)
- Complete codebase restructuring
- Enhanced security features
- Improved user experience
- Better mobile responsiveness
- Centralized configuration management

### v1.0.0
- Initial release
- Basic habit tracking functionality
- User authentication
- Dashboard interface

## Required Assets

The following files are referenced by the application and should be present in the `assets/images`, `assets/css`, and `assets/js` directories:

- `assets/images/default-user.png` (default user profile image)
- `assets/images/Logo.png` (site logo)
- `assets/css/style.css` (custom styles)
- `assets/js/app.js` (custom JavaScript)

If these files or directories do not exist, create them and add appropriate placeholder files to prevent broken images or missing styles/scripts.

Example placeholder creation:

```sh
mkdir -p assets/images assets/css assets/js
cp logo.png assets/images/Logo.png
# Add a placeholder for default-user.png
cp logo.png assets/images/default-user.png
# Create empty style.css and app.js if needed
> assets/css/style.css
> assets/js/app.js
```

---

**Trackie.in** - Track your habits, achieve your goals! 🎯


 
