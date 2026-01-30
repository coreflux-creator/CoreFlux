<?php
/**
 * CoreFlux Event System
 * Handles module-to-shell communication
 * 
 * Events:
 * - cf:title     - Update page title
 * - cf:navigate  - Navigate to a page
 * - cf:toast     - Show a toast notification
 * - cf:modal     - Open/close modal
 */

/**
 * Emit JavaScript for the event system
 */
function cfEventSystem(): void {
?>
<script>
/**
 * CoreFlux Event Bus
 */
window.CoreFlux = window.CoreFlux || {};

CoreFlux.events = {
    /**
     * Dispatch a custom event
     */
    dispatch(eventName, detail = {}) {
        document.dispatchEvent(new CustomEvent(eventName, { detail }));
    },
    
    /**
     * Listen for a custom event
     */
    on(eventName, callback) {
        document.addEventListener(eventName, (e) => callback(e.detail));
    },
    
    /**
     * Remove event listener
     */
    off(eventName, callback) {
        document.removeEventListener(eventName, callback);
    }
};

/**
 * Update page title
 * @param {string} title
 */
CoreFlux.setTitle = function(title) {
    document.title = title + ' | CoreFlux';
    CoreFlux.events.dispatch('cf:title', { title });
};

/**
 * Navigate to a page (AJAX or full reload)
 * @param {string} url
 * @param {boolean} ajax - Use AJAX navigation
 */
CoreFlux.navigate = function(url, ajax = true) {
    CoreFlux.events.dispatch('cf:navigate', { url, ajax });
    
    if (ajax) {
        // AJAX navigation
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => res.ok ? res.text() : Promise.reject('Failed'))
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.querySelector('#main-content, .cf-shell-main');
                const main = document.querySelector('#main-content, .cf-shell-main');
                if (newContent && main) {
                    main.innerHTML = newContent.innerHTML;
                    window.history.pushState({}, '', url);
                }
            })
            .catch(() => window.location.href = url);
    } else {
        window.location.href = url;
    }
};

/**
 * Show a toast notification
 * @param {string} message
 * @param {string} type - success, warning, danger, info
 * @param {number} duration - milliseconds
 */
CoreFlux.toast = function(message, type = 'info', duration = 4000) {
    CoreFlux.events.dispatch('cf:toast', { message, type, duration });
    
    // Create toast container if not exists
    let container = document.querySelector('.cf-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'cf-toast-container';
        document.body.appendChild(container);
    }
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `cf-toast cf-toast-${type}`;
    toast.innerHTML = `
        <span class="cf-toast-message">${message}</span>
        <button class="cf-toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    container.appendChild(toast);
    
    // Auto-remove after duration
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }, duration);
};

/**
 * API helper with base URL injection
 */
CoreFlux.api = {
    baseUrl: '/api',
    
    async request(endpoint, options = {}) {
        const url = this.baseUrl + endpoint;
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        };
        
        const response = await fetch(url, { ...defaultOptions, ...options });
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || 'API request failed');
        }
        
        return data;
    },
    
    get(endpoint) {
        return this.request(endpoint, { method: 'GET' });
    },
    
    post(endpoint, body) {
        return this.request(endpoint, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    },
    
    put(endpoint, body) {
        return this.request(endpoint, {
            method: 'PUT',
            body: JSON.stringify(body),
        });
    },
    
    delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    },
};

/**
 * Dropdown toggle helper
 */
function toggleDropdown(id) {
    const dropdown = document.getElementById(id);
    const isOpen = dropdown.classList.contains('open');
    
    // Close all dropdowns
    document.querySelectorAll('.cf-dropdown.open').forEach(d => d.classList.remove('open'));
    
    // Toggle this one
    if (!isOpen) {
        dropdown.classList.add('open');
    }
}

// Close dropdowns on click outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.cf-dropdown')) {
        document.querySelectorAll('.cf-dropdown.open').forEach(d => d.classList.remove('open'));
    }
});

// Handle browser back/forward
window.addEventListener('popstate', () => {
    window.location.reload();
});

// AJAX navigation for sidebar links
document.addEventListener('click', (e) => {
    const link = e.target.closest('.cf-nav-item');
    if (!link) return;
    
    e.preventDefault();
    CoreFlux.navigate(link.href);
    
    // Update active state
    document.querySelectorAll('.cf-nav-item').forEach(l => l.classList.remove('active'));
    link.classList.add('active');
});
</script>
<?php
}
