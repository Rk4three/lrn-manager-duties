// Team Calendar View JavaScript
// Handles view toggling between My Schedule and All Managers

var currentView = 'my'; // 'my' or 'all'
var allSchedulesData = null;
var currentDayManagers = [];
var currentDayManagerId = null; // Track current user's manager ID

// Pagination variables
var currentPage = 1;
var managersPerPage = 5;
var filteredManagers = []; // Managers after filtered

// Initialize view from URL
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const viewParam = urlParams.get('view');
    if (viewParam === 'all') {
        switchView('all');
    }
});

// Switch between My Schedule and All Managers view
function switchView(view) {
    currentView = view;
    const myBtn = document.getElementById('my-schedule-btn');
    const allBtn = document.getElementById('all-managers-btn');
    const hintText = document.getElementById('hint-text');
    
    // Update URL without reload
    const url = new URL(window.location);
    if (view === 'all') {
        url.searchParams.set('view', 'all');
    } else {
        url.searchParams.delete('view');
    }
    window.history.pushState({}, '', url);
    
    // Update Next/Prev Links
    updateNavigationLinks(view);
    
    if (view === 'my') {
        myBtn.classList.add('active');
        allBtn.classList.remove('active');
        hintText.textContent = 'Click on any day to add or edit your schedule';
        renderMySchedule();
    } else {
        myBtn.classList.remove('active');
        allBtn.classList.add('active');
        hintText.textContent = 'Click on any day to view all managers\' schedules or add yours';
        loadAllSchedules();
    }
}

function updateNavigationLinks(view) {
    const links = document.querySelectorAll('a[href*="?year="]');
    links.forEach(link => {
        let href = link.getAttribute('href');
        // Remove existing view param
        href = href.replace(/&view=[^&]*/, '');
        
        if (view === 'all') {
            href += '&view=all';
        }
        link.setAttribute('href', href);
    });
}

// Load all managers' schedules for the month
function loadAllSchedules() {
    const urlParams = new URLSearchParams(window.location.search);
    const year = urlParams.get('year') || new Date().getFullYear();
    const month = urlParams.get('month') || (new Date().getMonth() + 1);
    
    fetch(`actions/get_all_schedules.php?year=${year}&month=${month}&_=${Date.now()}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                allSchedulesData = data;
                renderAllSchedulesView();
            } else {
                console.error('Failed to load schedules:', data.message);
            }
        })
        .catch(err => console.error('Error loading schedules:', err));
}

// Render All Managers view with count badges
function renderAllSchedulesView() {
    if (!allSchedulesData) return;
    
    const summary = allSchedulesData.summary;
    const calendarDays = document.querySelectorAll('.calendar-day[data-date]');
    
    calendarDays.forEach(dayEl => {
        const date = dayEl.getAttribute('data-date');
        const daySummary = summary[date];
        
        // Clear existing content
        const contentDiv = dayEl.querySelector('.day-content');
        if (!contentDiv) return;
        
        contentDiv.innerHTML = '';
        
        if (daySummary) {
            // Show count badges
            const workCount = daySummary.work;
            const leaveCount = daySummary.leave;
            
            if (workCount > 0) {
                const workBadge = document.createElement('div');
                workBadge.className = 'count-badge badge-work';
                workBadge.innerHTML = `<i class="fas fa-briefcase text-xs"></i> ${workCount}`;
                contentDiv.appendChild(workBadge);
            }
            
            if (leaveCount > 0) {
                const leaveBadge = document.createElement('div');
                leaveBadge.className = 'count-badge badge-leave';
                leaveBadge.innerHTML = `<i class="fas fa-umbrella-beach text-xs"></i> ${leaveCount}`;
                contentDiv.appendChild(leaveBadge);
            }
        }
    });
}

// Render My Schedule view (restore personal entries)
function renderMySchedule() {
    // Reload the page to restore original view
    // This is simpler than maintaining two separate data sets
    location.reload();
}

// Refresh team view data (called after saving a schedule)
function refreshTeamView() {
    if (currentView === 'all') {
        loadAllSchedules();
    }
}

// Open day detail modal showing all managers for a specific day
function openDayDetail(date) {
    if (currentView !== 'all') return;
    
    const urlParams = new URLSearchParams(window.location.search);
    const year = urlParams.get('year') || new Date().getFullYear();
    const month = urlParams.get('month') || (new Date().getMonth() + 1);
    const day = date.split('-')[2];
    
    fetch(`actions/get_all_schedules.php?year=${year}&month=${month}&day=${day}&_=${Date.now()}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                currentDayManagers = data.entries;
                showDayDetailModal(date, data);
            }
        })
        .catch(err => console.error('Error loading day details:', err));
}

// Show the day detail modal with manager list
function showDayDetailModal(date, data) {
    // Reset pagination to page 1
    currentPage = 1;
    
    const modal = document.getElementById('day-detail-modal');
    const title = document.getElementById('day-modal-title');
    const countEl = document.getElementById('day-modal-count');
    const tableBody = document.getElementById('managers-table-body');
    const noManagersMsg = document.getElementById('no-managers-msg');
    const addMyScheduleBtn = document.getElementById('add-my-schedule-btn');
    
    // Format date
    const dateObj = new Date(date + 'T00:00:00');
    const formattedDate = dateObj.toLocaleDateString('en-US', { 
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
    });
    
    title.textContent = `All Managers - ${formattedDate}`;
    countEl.textContent = `${data.count} manager${data.count !== 1 ? 's' : ''}`;
    
    // Set up "Add My Schedule" button - find current user's entry
    const currentUserId = document.querySelector('[data-manager-id]')?.getAttribute('data-manager-id');
    const isSuperAdmin = document.body.getAttribute('data-is-super-admin') === '1';
    console.log('DayDetail: isSuperAdmin detected:', isSuperAdmin, 'Raw:', document.body.getAttribute('data-is-super-admin'));
    const currentUserEntry = data.entries.find(e => e.manager_id == currentUserId);
    
    // Assign Manager Button (Super Admin)
    const assignBtn = document.getElementById('assign-manager-btn');
    if (assignBtn) {
        if (isSuperAdmin) {
            assignBtn.classList.remove('hidden');
            assignBtn.onclick = function() {
                closeDayDetailModal();
                // openEntryModal(date, id, assignMode)
                openEntryModal(date, null, true);
            };
            // Show Action Header
            const actionHeader = document.getElementById('action-header');
            if (actionHeader) actionHeader.classList.remove('hidden');
        } else {
            assignBtn.classList.add('hidden');
            const actionHeader = document.getElementById('action-header');
            if (actionHeader) actionHeader.classList.add('hidden');
        }
    }

    if (currentUserEntry) {
        addMyScheduleBtn.innerHTML = '<i class="fas fa-edit text-sm mr-2"></i> Edit My Schedule';
    } else {
        addMyScheduleBtn.innerHTML = '<i class="fas fa-plus text-sm mr-2"></i> Add My Schedule';
    }

    addMyScheduleBtn.onclick = function() {
        closeDayDetailModal();
        openEntryModal(date, currentUserEntry ? currentUserEntry.id : null);
    };
    
    if (data.count === 0) {
        tableBody.innerHTML = '';
        noManagersMsg.classList.remove('hidden');
    } else {
        noManagersMsg.classList.add('hidden');
        populateManagersTable(data.entries);
    }
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
        modal.querySelector('.glass-card').classList.remove('scale-95');
        modal.querySelector('.glass-card').classList.add('scale-100');
    }, 10);
}

// Populate the managers table
function populateManagersTable(managers) {
    const tableBody = document.getElementById('managers-table-body');
    const isSuperAdmin = document.body.getAttribute('data-is-super-admin') === '1';
    console.log('Populating Table. isSuperAdmin:', isSuperAdmin);
    tableBody.innerHTML = '';
    
    // Store filtered managers for pagination
    filteredManagers = managers;
    
    // Calculate pagination
    const totalPages = Math.ceil(managers.length / managersPerPage);
    const startIndex = (currentPage - 1) * managersPerPage;
    const endIndex = Math.min(startIndex + managersPerPage, managers.length);
    const paginatedManagers = managers.slice(startIndex, endIndex);
    
    // Update pagination controls
    updatePaginationControls(managers.length, totalPages, startIndex, endIndex);
    
    paginatedManagers.forEach(manager => {
        const row = document.createElement('tr');
        row.className = 'border-b border-slate-800 hover:bg-slate-800/30 transition-colors manager-row';
        row.setAttribute('data-name', manager.manager_name.toLowerCase());
        row.setAttribute('data-dept', (manager.department || '').toLowerCase());
        
        const scheduleText = manager.entry_type === 'WORK' 
            ? `<span class="text-green-400"><i class="fas fa-briefcase text-xs"></i> ${manager.start_time} - ${manager.end_time}</span>`
            : `<span class="text-orange-400"><i class="fas fa-umbrella-beach text-xs"></i> ${manager.leave_note}</span>`;
        
        const createdDate = manager.created_at ? new Date(manager.created_at).toLocaleString('en-US', {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
        }) : 'N/A';
        
        let actionCell = '';
        if (isSuperAdmin) {
            actionCell = `
                <td class="py-3 px-4 text-center">
                    <button onclick="deleteManagerEntry(${manager.id}, '${manager.manager_name.replace(/'/g, "\\'")}')" 
                        class="text-slate-500 hover:text-rose-500 transition-colors p-2 rounded-full hover:bg-rose-500/10"
                        title="Delete Schedule">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            `;
        }
        
        row.innerHTML = `
            <td class="py-3 px-4">
                <img src="${manager.photo_url || 'assets/img/mystery-man.png'}" 
                     alt="${manager.manager_name}"
                     class="manager-photo border border-white/10"
                     onerror="this.src='assets/img/mystery-man.png'">
            </td>
            <td class="py-3 px-4 font-medium text-white">${manager.manager_name}</td>
            <td class="py-3 px-4 text-slate-300">${manager.department || 'N/A'}</td>
            <td class="py-3 px-4 text-slate-400 font-mono text-sm">${manager.employee_id || 'N/A'}</td>
            <td class="py-3 px-4">${scheduleText}</td>
            <td class="py-3 px-4 text-slate-400 text-sm">${createdDate}</td>
            ${actionCell}
        `;
        
        tableBody.appendChild(row);
    });
    
    // Fill remaining slots with empty rows to maintain consistent height
    const remainingSlots = managersPerPage - paginatedManagers.length;
    for (let i = 0; i < remainingSlots; i++) {
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'border-b border-slate-800/20';
        emptyRow.innerHTML = `
            <td class="py-3 px-4"><div class="manager-photo bg-slate-800/30 rounded-full"></div></td>
            <td class="py-3 px-4"><div class="h-4 w-32 bg-slate-800/30 rounded"></div></td>
            <td class="py-3 px-4"><div class="h-4 w-24 bg-slate-800/30 rounded"></div></td>
            <td class="py-3 px-4"><div class="h-4 w-16 bg-slate-800/30 rounded"></div></td>
            <td class="py-3 px-4"><div class="h-6 w-40 bg-slate-800/30 rounded"></div></td>
            <td class="py-3 px-4"><div class="h-4 w-24 bg-slate-800/30 rounded"></div></td>
            ${isSuperAdmin ? '<td class="py-3 px-4"></td>' : ''}
        `;
        // Make opaque to just be spacing, or visible placeholders?
        // Let's make them invisible spacers to keep it clean but consistent size
        emptyRow.style.visibility = 'hidden'; 
        tableBody.appendChild(emptyRow);
    }
}

// Close day detail modal
function closeDayDetailModal() {
    const modal = document.getElementById('day-detail-modal');
    modal.querySelector('.glass-card').classList.remove('scale-100');
    modal.querySelector('.glass-card').classList.add('scale-95');
    setTimeout(() => {
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }, 200);
}

// Filter managers by search input
function filterManagers() {
    const searchInput = document.getElementById('manager-search');
    const filter = searchInput.value.toLowerCase();
    
    // Filter the current day managers
    const filtered = currentDayManagers.filter(manager => {
        const name = manager.manager_name.toLowerCase();
        const dept = (manager.department || '').toLowerCase();
        return name.includes(filter) || dept.includes(filter);
    });
    
    // Reset to page 1 when filtering
    currentPage = 1;
    
    // Re-populate table with filtered results
    populateManagersTable(filtered);
}

// Sort table by column
let sortDirection = { name: 1, dept: 1 };

function sortTable(column) {
    const tbody = document.getElementById('managers-table-body');
    const rows = Array.from(tbody.querySelectorAll('.manager-row'));
    
    rows.sort((a, b) => {
        const aValue = a.getAttribute(`data-${column}`);
        const bValue = b.getAttribute(`data-${column}`);
        
        if (aValue < bValue) return -1 * sortDirection[column];
        if (aValue > bValue) return 1 * sortDirection[column];
        return 0;
    });
    
    sortDirection[column] *= -1;
    
    tbody.innerHTML = '';
    rows.forEach(row => tbody.appendChild(row));
}

function deleteManagerEntry(entryId, managerName) {
    if (!confirm(`Are you sure you want to delete the schedule for ${managerName}?`)) return;

    const formData = new FormData();
    formData.append('entry_id', entryId);

    fetch('actions/delete_calendar_entry.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeDayDetailModal();
            loadAllSchedules(); // Refresh view
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(err => {
        console.error('Error deleting entry:', err);
        alert('Failed to delete entry.');
    });
}

// Update pagination controls
function updatePaginationControls(totalCount, totalPages, startIndex, endIndex) {
    const paginationControls = document.getElementById('pagination-controls');
    const prevBtn = document.getElementById('prev-page-btn');
    const nextBtn = document.getElementById('next-page-btn');
    const currentPageEl = document.getElementById('current-page');
    const totalPagesEl = document.getElementById('total-pages');
    const showingRangeEl = document.getElementById('showing-range');
    const totalManagersEl = document.getElementById('total-managers');
    
    // Show/hide pagination based on total count
    if (totalCount > managersPerPage) {
        paginationControls.classList.remove('hidden');
    } else {
        paginationControls.classList.add('hidden');
    }
    
    // Update page info
    currentPageEl.textContent = currentPage;
    totalPagesEl.textContent = totalPages;
    showingRangeEl.textContent = totalCount > 0 ? `${startIndex + 1}-${endIndex}` : '0';
    totalManagersEl.textContent = totalCount;
    
    // Enable/disable buttons
    prevBtn.disabled = currentPage === 1;
    nextBtn.disabled = currentPage === totalPages || totalPages === 0;
}

// Change page
function changePage(direction) {
    const totalPages = Math.ceil(filteredManagers.length / managersPerPage);
    const newPage = currentPage + direction;
    
    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        populateManagersTable(filteredManagers);
    }
}
