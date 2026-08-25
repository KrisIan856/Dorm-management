/**
 * dashboard_enhancements.js - Modern Enterprise Features & Interactivity
 * Theme switcher, live global search filter, interactive room matrix visualizer, mobile navigation drawer
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Theme Switcher Engine (Light / Dark Mode)
    initThemeSwitcher();

    // 2. Global Live Search Filtering
    initGlobalSearch();

    // 3. Mobile Sidebar Drawer Toggle
    initMobileSidebar();

    // 4. Interactive Room Visualizer Grid
    initRoomMatrixVisualizer();

    // 5. Dynamic Live Time & Greeting Update
    initLiveGreeting();
});

/**
 * Theme Switcher with localStorage persistence
 */
function initThemeSwitcher() {
    const savedTheme = localStorage.getItem('dorm_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);

    const toggleBtns = document.querySelectorAll('.theme-toggle-btn');
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('dorm_theme', newTheme);
            updateThemeIcon(newTheme);

            // Optional subtle toast notification
            showToast('Theme Changed', `Switched to ${newTheme} mode`, 'info');
        });
    });
}

function updateThemeIcon(theme) {
    const icons = document.querySelectorAll('.theme-toggle-btn i');
    icons.forEach(icon => {
        if (theme === 'dark') {
            icon.className = 'fas fa-sun';
            icon.style.color = '#f59e0b';
        } else {
            icon.className = 'fas fa-moon';
            icon.style.color = '';
        }
    });
}

/**
 * Live Search Filter across visible tables & card lists
 */
function initGlobalSearch() {
    const searchInput = document.getElementById('globalSearchInput');
    if (!searchInput) return;

    // Keyboard shortcut Ctrl+K or /
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
    });

    searchInput.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        const activeSection = document.querySelector('.section.active, .tab-content.active') || document.body;

        // Search table rows
        const rows = activeSection.querySelectorAll('table tbody tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            if (text.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });

        // Search item cards (e.g., room-item, request-item, etc.)
        const cards = activeSection.querySelectorAll('.room-item, .request-item, .fine-item, .visitor-item, .stat-card');
        cards.forEach(card => {
            const text = card.innerText.toLowerCase();
            if (text.includes(query)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
}

/**
 * Responsive Mobile Navigation Drawer
 */
function initMobileSidebar() {
    const toggleBtn = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (!sidebar) return;

    // Create backdrop element if missing
    let backdrop = document.querySelector('.sidebar-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        document.body.appendChild(backdrop);
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            sidebar.classList.toggle('mobile-open');
            backdrop.classList.toggle('active');
        });
    }

    backdrop.addEventListener('click', function () {
        sidebar.classList.remove('mobile-open');
        backdrop.classList.remove('active');
    });

    // Close menu when clicking sidebar links on mobile
    const links = sidebar.querySelectorAll('.sidebar-menu a');
    links.forEach(link => {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('mobile-open');
                backdrop.classList.remove('active');
            }
        });
    });
}

/**
 * Interactive Room Floorplan Matrix Visualizer
 * Dynamically builds a visual 2D grid from room data found on page
 */
function initRoomMatrixVisualizer() {
    const roomTable = document.querySelector('#rooms-section table, #rooms table, .room-table');
    const visualizerTarget = document.getElementById('roomMatrixVisualizer');

    if (!visualizerTarget) return;

    // Build grid from DOM tables or data attributes if available
    let roomElements = [];
    if (roomTable) {
        const rows = roomTable.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const cols = row.querySelectorAll('td');
            if (cols.length >= 3) {
                const roomNum = cols[0].innerText.trim();
                const roomType = cols[1].innerText.trim();
                const statusText = cols[cols.length - 1].innerText.toLowerCase();

                let status = 'available';
                if (statusText.includes('occupied') || statusText.includes('taken')) status = 'occupied';
                if (statusText.includes('maintenance') || statusText.includes('repair')) status = 'maintenance';
                if (statusText.includes('reserve')) status = 'reserved';

                roomElements.push({ number: roomNum, type: roomType, status: status });
            }
        });
    }

    if (roomElements.length === 0) {
        // Fallback sample data for rich UI presentation if database table is empty
        roomElements = [
            { number: '101', type: 'Single Standard', status: 'occupied' },
            { number: '102', type: 'Single Deluxe', status: 'available' },
            { number: '103', type: 'Double Suite', status: 'occupied' },
            { number: '104', type: 'Single Standard', status: 'maintenance' },
            { number: '105', type: 'Double Suite', status: 'available' },
            { number: '201', type: 'Executive Suite', status: 'occupied' },
            { number: '202', type: 'Single Standard', status: 'available' },
            { number: '203', type: 'Double Deluxe', status: 'available' }
        ];
    }

    let gridHtml = `<div class="room-visualizer-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h4 style="font-weight: 700; font-size: 1rem;"><i class="fas fa-th"></i> Interactive Room Status Map</h4>
            <div style="display: flex; gap: 12px; font-size: 0.78rem; font-weight: 600;">
                <span><i class="fas fa-circle" style="color: var(--success);"></i> Available</span>
                <span><i class="fas fa-circle" style="color: var(--primary);"></i> Occupied</span>
                <span><i class="fas fa-circle" style="color: var(--danger);"></i> Maintenance</span>
            </div>
        </div>
        <div class="room-grid-matrix">`;

    roomElements.forEach(room => {
        let icon = room.status === 'available' ? 'fa-door-open' : (room.status === 'occupied' ? 'fa-user-check' : 'fa-tools');
        gridHtml += `
            <div class="room-matrix-card ${room.status}" title="Room ${room.number} - ${room.type}">
                <i class="fas ${icon}" style="font-size: 1.2rem; margin-bottom: 6px;"></i>
                <div class="room-matrix-number">R-${room.number}</div>
                <div class="room-matrix-type">${room.type}</div>
                <div class="room-matrix-capacity">
                    <span class="badge ${room.status === 'available' ? 'badge-success' : (room.status === 'occupied' ? 'badge-primary' : 'badge-danger')}">
                        ${room.status.toUpperCase()}
                    </span>
                </div>
            </div>`;
    });

    gridHtml += `</div></div>`;
    visualizerTarget.innerHTML = gridHtml;
}

/**
 * Real-time Greeting with Digital Clock
 */
function initLiveGreeting() {
    const greetingEl = document.getElementById('liveGreeting');
    if (!greetingEl) return;

    function updateTime() {
        const now = new Date();
        const hours = now.getHours();
        let greeting = 'Good Morning';
        if (hours >= 12 && hours < 17) greeting = 'Good Afternoon';
        else if (hours >= 17) greeting = 'Good Evening';

        const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        greetingEl.innerHTML = `<span>${greeting}</span> <small style="opacity:0.75; font-weight:normal; margin-left: 8px;"><i class="far fa-clock"></i> ${dateStr}, ${timeStr}</small>`;
    }

    updateTime();
    setInterval(updateTime, 30000);
}

/**
 * Toast Notification Utility
 */
function showToast(title, message, type = 'info') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const borderColors = { success: '#10b981', danger: '#ef4444', info: '#3b82f6', warning: '#f59e0b' };
    const icons = { success: 'fa-check-circle', danger: 'fa-exclamation-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle' };

    toast.style.cssText = `
        background: var(--bg-surface);
        color: var(--text-primary);
        border-left: 4px solid ${borderColors[type] || borderColors.info};
        border-radius: var(--radius-md);
        padding: 14px 18px;
        box-shadow: var(--shadow-lg);
        min-width: 280px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideInRight 0.3s ease;
    `;

    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}" style="color: ${borderColors[type]}; font-size: 1.3rem;"></i>
        <div>
            <div style="font-weight: 700; font-size: 0.88rem;">${title}</div>
            <div style="font-size: 0.8rem; color: var(--text-secondary);">${message}</div>
        </div>
    `;

    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}
