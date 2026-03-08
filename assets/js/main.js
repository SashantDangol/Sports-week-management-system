// ========== MAIN JS ==========

document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert-success').forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() { alert.remove(); }, 500);
        }, 5000);
    });
    
    // Form validation - prevent same sport selection
    const sport1 = document.querySelector('select[name="sport1"]');
    const sport2 = document.querySelector('select[name="sport2"]');
    
    if (sport1 && sport2) {
        const form = sport1.closest('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (sport1.value && sport2.value && sport1.value === sport2.value) {
                    e.preventDefault();
                    alert('Please choose 2 different sports!');
                }
            });
        }
    }
    
    // Date validation
    const regStart = document.getElementById('reg_start_date');
    const regEnd = document.getElementById('reg_end_date');
    const eventStart = document.getElementById('event_start_date');
    const eventEnd = document.getElementById('event_end_date');
    
    if (regStart && regEnd) {
        regStart.addEventListener('change', function() {
            regEnd.min = this.value;
        });
        regEnd.addEventListener('change', function() {
            if (eventStart) eventStart.min = this.value;
        });
        if (eventStart) {
            eventStart.addEventListener('change', function() {
                if (eventEnd) eventEnd.min = this.value;
            });
        }
    }
    
    // Confirm dangerous actions
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
});
