/**
 * Trackie.in - Main JavaScript Application
 * Handles all interactive functionality and animations
 */
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarClose = document.getElementById('sidebarClose');

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', toggleSidebar);
}

if (sidebarClose) {
    sidebarClose.addEventListener('click', closeSidebar);
}

// Global variables
let currentTheme = 'light';
let isSidebarOpen = false;

// DOM Content Loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

/**
 * Initialize the application
 */
function initializeApp() {
    setupEventListeners();
    initializeAnimations();
    loadUserPreferences();
    setupFormValidation();
    initializeToastSystem();
}

/**
 * Setup all event listeners
 */
function setupEventListeners() {
    // Sidebar toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', closeSidebar);
    }
    
    // Dark mode toggle
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', toggleDarkMode);
    }
    
    // Quick add button
    const quickAddBtn = document.getElementById('quickAddBtn');
    if (quickAddBtn) {
        quickAddBtn.addEventListener('click', showQuickAddModal);
    }
    
    // Todo checkboxes
    setupTodoCheckboxes();
    
    // Form submissions
    setupFormSubmissions();
    
    // Click outside to close sidebar
    document.addEventListener('click', function(e) {
        if (isSidebarOpen && !sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            closeSidebar();
        }
    });
}

/**
 * Initialize animations
 */
function initializeAnimations() {
    // Animate elements on scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in');
            }
        });
    }, observerOptions);
    
    // Observe all cards and sections
    document.querySelectorAll('.luxury-card, .stat-card, .todo-item').forEach(el => {
        observer.observe(el);
    });
}

/**
 * Load user preferences from localStorage
 */
function loadUserPreferences() {
    const savedTheme = localStorage.getItem('trackie-theme');
    if (savedTheme) {
        currentTheme = savedTheme;
        applyTheme();
    }
    
    const sidebarState = localStorage.getItem('trackie-sidebar');
    if (sidebarState === 'open') {
        openSidebar();
    }
}

/**
 * Setup todo checkboxes
 */
function setupTodoCheckboxes() {
    document.querySelectorAll('.todo-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const todoId = this.dataset.todoId;
            const completed = this.checked;
            
            toggleTodo(todoId, completed);
        });
    });
}

/**
 * Setup form submissions
 */
function setupFormSubmissions() {
    const forms = document.querySelectorAll('form[data-ajax="true"]');
    forms.forEach(form => {
        form.addEventListener('submit', handleAjaxForm);
    });
}

/**
 * Toggle sidebar
 */
function toggleSidebar() {
    if (isSidebarOpen) {
        closeSidebar();
    } else {
        openSidebar();
    }
}

/**
 * Open sidebar
 */
function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const icon = sidebarToggle.querySelector('i');

    if (sidebar) {
        sidebar.classList.remove('-translate-x-full');
        isSidebarOpen = true;
        localStorage.setItem('trackie-sidebar', 'open');

        // Hide the bars icon when open
        if (icon) {
            icon.classList.add('hidden');
        }
    }
}

/**
 * Close sidebar
 */
function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const icon = sidebarToggle.querySelector('i');

    if (sidebar) {
        sidebar.classList.add('-translate-x-full');
        isSidebarOpen = false;
        localStorage.setItem('trackie-sidebar', 'closed');

        // Show the bars icon again
        if (icon) {
            icon.classList.remove('hidden');
        }
    }
}


/**
 * Toggle dark mode
 */
function toggleDarkMode() {
    currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
    applyTheme();
    localStorage.setItem('trackie-theme', currentTheme);
    
    const icon = document.querySelector('#darkModeToggle i');
    if (icon) {
        icon.className = currentTheme === 'dark' ? 'fa fa-moon' : 'fa fa-sun';
    }
}

/**
 * Apply current theme
 */
function applyTheme() {
    document.body.className = currentTheme === 'dark' ? 'dark-mode' : '';
}

/**
 * Show quick add modal
 */
function showQuickAddModal() {
    showToast('Quick Add feature coming soon! 🚀');
}

/**
 * Toggle todo completion
 */
function toggleTodo(todoId, completed) {
    const formData = new FormData();
    formData.append('action', 'toggle_todo');
    formData.append('todo_id', todoId);
    formData.append('completed', completed ? '1' : '0');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateTodoUI(todoId, completed);
            updateStats();
            showToast(completed ? 'Todo completed! 🎉' : 'Todo marked as pending');
        } else {
            showToast('Error updating todo', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error updating todo', 'error');
    });
}

/**
 * Update todo UI
 */
function updateTodoUI(todoId, completed) {
    const todoItem = document.querySelector(`[data-todo-id="${todoId}"]`);
    if (todoItem) {
        if (completed) {
            todoItem.classList.add('completed');
        } else {
            todoItem.classList.remove('completed');
        }
    }
}

/**
 * Update statistics
 */
function updateStats() {
    // This would typically fetch updated stats from the server
    // For now, we'll just show a success message
    showToast('Stats updated! 📊');
}

/**
 * Handle AJAX form submissions
 */
function handleAjaxForm(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Show loading state
    if (submitBtn) {
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;
    }
    
    fetch(form.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Success!');
            if (data.redirect) {
                setTimeout(() => {
                    window.location.href = data.redirect;
                }, 1000);
            }
        } else {
            showToast(data.message || 'Error occurred', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred', 'error');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
        }
    });
}

/**
 * Initialize toast notification system
 */
function initializeToastSystem() {
    // Create toast container if it doesn't exist
    if (!document.getElementById('toast-container')) {
        const toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'fixed bottom-6 right-6 z-50 space-y-2';
        document.body.appendChild(toastContainer);
    }
}

/**
 * Show toast notification
 */
function showToast(message, type = 'success', duration = 3000) {
    const toastContainer = document.getElementById('toast-container');
    if (!toastContainer) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type === 'error' ? 'bg-red-600' : 'bg-green-600'} text-white px-6 py-3 rounded-lg shadow-lg slide-in`;
    toast.textContent = message;
    
    toastContainer.appendChild(toast);
    
    // Auto remove after duration
    setTimeout(() => {
        toast.classList.add('opacity-0');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, duration);
}

/**
 * Validate form inputs
 */
function validateForm(form) {
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            isValid = false;
            highlightError(input);
        } else {
            clearError(input);
        }
    });
    
    return isValid;
}

/**
 * Highlight input error
 */
function highlightError(input) {
    input.classList.add('border-red-500');
    input.classList.add('bg-red-50');
}

/**
 * Clear input error
 */
function clearError(input) {
    input.classList.remove('border-red-500');
    input.classList.remove('bg-red-50');
}

/**
 * Setup form validation
 */
function setupFormValidation() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                showToast('Please fill in all required fields', 'error');
            }
        });
    });
}

/**
 * Smooth scroll to element
 */
function scrollToElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Format date for display
 */
function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Format time for display
 */
function formatTime(time) {
    return new Date(`2000-01-01T${time}`).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Generate random ID
 */
function generateId() {
    return Math.random().toString(36).substr(2, 9);
}

/**
 * Copy text to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Copied to clipboard!');
    }).catch(() => {
        showToast('Failed to copy', 'error');
    });
}

/**
 * Export data as JSON
 */
function exportData(data, filename) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

/**
 * Import data from file
 */
function importData(file, callback) {
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = JSON.parse(e.target.result);
            callback(data);
        } catch (error) {
            showToast('Invalid file format', 'error');
        }
    };
    reader.readAsText(file);
}

// Export functions for global use
window.TrackieApp = {
    showToast,
    toggleSidebar,
    toggleDarkMode,
    scrollToElement,
    formatDate,
    formatTime,
    copyToClipboard,
    exportData,
    importData
}; 