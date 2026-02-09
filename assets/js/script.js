/**
 * Duty Manager Checklist - JavaScript
 * Handles shift selection, filtering, image upload, and form submission
 */

// ============================================
// SHIFT SELECTION LOGIC (Radio-button behavior)
// ============================================
document.querySelectorAll(".shift-btn").forEach((btn) => {
  btn.addEventListener("click", function () {
    // Check if editing is allowed
    if (typeof canEdit !== 'undefined' && !canEdit) return;
    if (this.disabled) return;

    const itemId = this.dataset.item;
    const selectedShift = this.dataset.shift;

    // Find all shift buttons for this item
    const allShiftBtns = document.querySelectorAll(
      `.shift-btn[data-item="${itemId}"]`,
    );

    // Check if this button was already selected
    const wasSelected = this.classList.contains("selected");

    // Reset all buttons for this item
    allShiftBtns.forEach((b) => {
      b.classList.remove("selected");
      b.disabled = false;
    });

    if (!wasSelected) {
      // Select this button
      this.classList.add("selected");
      allShiftBtns.forEach((b) => {
        if (b !== this) {
          // b.disabled = true; // No longer disable other buttons
        }
      });

      // Update item status
      updateItemStatus(itemId, true);

      // Save to server
      saveEntry(itemId, selectedShift);
    } else {
      // Deselecting - clear shift
      updateItemStatus(itemId, false);
      saveEntry(itemId, null);
    }
  });
});

// ============================================
// UPDATE ITEM STATUS (checked/unchecked)
// ============================================
function updateItemStatus(itemId, isChecked) {
  const items = document.querySelectorAll(
    `.checklist-item[data-item-id="${itemId}"]`,
  );
  items.forEach((item) => {
    item.dataset.checked = isChecked ? "1" : "0";
    item.classList.remove("checked-item", "unchecked-item");
    item.classList.add(isChecked ? "checked-item" : "unchecked-item");
  });
  updateProgressCount();
  applyFilters();
}

// ============================================
// PROGRESS COUNT
// ============================================
function updateProgressCount() {
  const checkedItems = document.querySelectorAll(
    '.checklist-item[data-checked="1"]',
  );
  // Count unique item IDs (both mobile and desktop views exist)
  const uniqueIds = new Set();
  checkedItems.forEach((item) => uniqueIds.add(item.dataset.itemId));

  const countEl = document.getElementById("progress-count");
  if (countEl) {
    countEl.textContent = uniqueIds.size;
  }
}

// ============================================
// SAVE ENTRY TO SERVER
// ============================================
let saveTimeout = {};

function saveEntry(itemId, shift) {
  // Get additional fields - only select visible inputs (not hidden Grid View duplicates)
  const allDeptInputs = document.querySelectorAll(
    `.dept-input[data-item="${itemId}"]`,
  );
  const deptInput = Array.from(allDeptInputs).find(input => input.offsetWidth > 0);
  
  // Collect remarks from all 2 shift inputs for this item (using the visible ones)
  const remarksData = { "1st": "", "2nd": "" };
  
  // Find visible inputs (desktop or mobile)
  const all1st = document.querySelectorAll(`.remarks-input[data-item="${itemId}"][data-shift-remark="1st"]`);
  const input1st = Array.from(all1st).find(input => input.offsetWidth > 0) || all1st[0];
  if (input1st) remarksData["1st"] = input1st.value;

  const all2nd = document.querySelectorAll(`.remarks-input[data-item="${itemId}"][data-shift-remark="2nd"]`);
  const input2nd = Array.from(all2nd).find(input => input.offsetWidth > 0) || all2nd[0];
  if (input2nd) remarksData["2nd"] = input2nd.value;

  const remarksJson = JSON.stringify(remarksData);
  
  const allCoordinatedChecks = document.querySelectorAll(
    `.coordinated-check[data-item="${itemId}"]`
  );
  const coordinatedCheck = Array.from(allCoordinatedChecks).find(check => check.offsetWidth > 0);
  
  const allTempInputs = document.querySelectorAll(
    `.temp-input[data-item="${itemId}"]`
  );
  const tempInput = Array.from(allTempInputs).find(input => input.offsetWidth > 0);

  const formData = new FormData();
  formData.append("action", "save_entry");
  formData.append("item_id", itemId);
  formData.append("shift", shift || "");
  formData.append("dept_in_charge", deptInput ? deptInput.value : "");
  formData.append("remarks", remarksJson);
  formData.append("coordinated", coordinatedCheck && coordinatedCheck.checked ? "1" : "0");
  formData.append("temperature", tempInput ? tempInput.value : "");

  fetch("checklist.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) {
        showError("Failed to save entry.");
      }
    })
    .catch((error) => {
      console.error("Error:", error);
    });
}

// Debounced save for text inputs
function debouncedSave(itemId) {
  if (saveTimeout[itemId]) {
    clearTimeout(saveTimeout[itemId]);
  }
  saveTimeout[itemId] = setTimeout(() => {
    // Get current shift
    const selectedBtn = document.querySelector(
      `.shift-btn[data-item="${itemId}"].selected`,
    );
    const shift = selectedBtn ? selectedBtn.dataset.shift : null;
    saveEntry(itemId, shift);
  }, 500);
}

// Text input listeners - debounced save on typing
document.querySelectorAll(".dept-input, .remarks-input, .temp-input").forEach((input) => {
  input.addEventListener("input", function () {
    // Check if editing is allowed
    if (typeof canEdit !== 'undefined' && !canEdit) return;
    debouncedSave(this.dataset.item);
  });
  
  // Immediate save on blur (when user clicks away or tabs out)
  input.addEventListener("blur", function () {
    if (typeof canEdit !== 'undefined' && !canEdit) return;
    // Clear any pending debounced save and save immediately
    const itemId = this.dataset.item;
    if (saveTimeout[itemId]) {
      clearTimeout(saveTimeout[itemId]);
      delete saveTimeout[itemId];
    }
    // Get current shift and save
    const selectedBtn = document.querySelector(
      `.shift-btn[data-item="${itemId}"].selected`,
    );
    const shift = selectedBtn ? selectedBtn.dataset.shift : null;
    saveEntry(itemId, shift);
  });
});

// Checkbox listeners
document.querySelectorAll(".coordinated-check").forEach((check) => {
    check.addEventListener("change", function() {
        if (typeof canEdit !== 'undefined' && !canEdit) return;
        debouncedSave(this.dataset.item);
    });
});

// ============================================
// IMAGE UPLOAD
// ============================================
// ============================================
// IMAGE UPLOAD DROPDOWN & LOGIC
// ============================================

// Direct Camera/Gallery Triggers
function triggerCamera(itemId) {
    // Find the camera input for this item
    // Note: There might be multiple inputs because of mobile/desktop separate views.
    // Just find one that exists and click it.
    const input = document.querySelector(`.image-input-camera[data-item="${itemId}"]`);
    
    if (input) {
        // Check for camera support if possible
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    // Camera is available
                    stream.getTracks().forEach(track => track.stop());
                    input.click();
                })
                .catch(function(err) {
                    if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                        showError("No camera detected on this device.");
                    } else if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                        showError("Camera permission denied. Please allow camera access.");
                    } else {
                        // On some devices, it might just fail if no permission, but we should try clicking anyway
                        // as the browser handles the file picker/camera intent.
                        console.warn("Camera check warning: " + err.message);
                        input.click(); 
                    }
                });
        } else {
            // Fallback for browsers without mediaDevices API or if we just want to rely on <input capture>
             input.click();
        }
    } else {
        console.error(`No camera input found for item ${itemId}`);
    }
}

function triggerGallery(itemId) {
    const input = document.querySelector(`.image-input-gallery[data-item="${itemId}"]`);
    if (input) {
        input.click();
    } else {
         console.error(`No gallery input found for item ${itemId}`);
    }
}

// Handle File Input Change (for both Camera and Gallery inputs)
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('image-input-camera') && 
        !e.target.classList.contains('image-input-gallery') && 
        !e.target.classList.contains('image-upload')) {
        return;
    }
    
    const input = e.target;
    const itemId = input.dataset.item;
    const file = input.files[0];

    if (!file) return;

    // Validate file type
    const validTypes = ["image/jpeg", "image/png", "image/gif", "image/webp"];
    if (!validTypes.includes(file.type)) {
      showError("File type not supported. Use JPG, PNG, GIF, or WebP.");
      return;
    }

    // Validate file size (max 25MB) - Increased for modern phone cameras
    if (file.size > 25 * 1024 * 1024) {
      showError("File is too large. Maximum size is 25MB.");
      return;
    }

    const formData = new FormData();
    formData.append("action", "upload_image");
    formData.append("item_id", itemId);
    formData.append("image", file);

    // Show loading state/UX if needed (optional)
    
    fetch("checklist.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          // Add thumbnail to list (new logic)
          addPhotoThumbnail(itemId, data.photo);
        } else {
          showError(data.error || "Failed to upload image.");
        }
        // Reset input so same file can be selected again if needed
        input.value = '';
      })
      .catch((error) => {
        console.error("Error:", error);
        showError("Failed to upload image.");
        input.value = '';
      });
});

/**
 * Appends a new photo thumbnail to the list
 * @param {string} itemId 
 * @param {object} photo { id, path }
 */
function addPhotoThumbnail(itemId, photo) {
  // Mobile and Desktop containers
  const containers = [
      document.getElementById(`preview-${itemId}-mobile`),
      document.getElementById(`preview-${itemId}`)
  ];

  containers.forEach(container => {
      if (!container) return;
      
      // Remove "No photos attached" text if present
      const emptyText = container.querySelector('span.italic, span.text-slate-700');
      if (emptyText && emptyText.innerText.includes('No')) emptyText.remove();
      // Also remove the "hidden" class if present
      container.classList.remove('hidden');
      if (!container.classList.contains('flex')) container.classList.add('flex');

      // Create thumbnail element
      const isMobile = container.id.includes('mobile');
      const sizeClass = isMobile ? 'w-16 h-16 rounded-lg' : 'w-8 h-8 rounded';
      const btnClass = isMobile ? 'p-1 rounded-bl-lg' : 'w-3 h-3 rounded-bl';
      const iconSize = isMobile ? 'text-[10px]' : 'text-[8px]';

      const div = document.createElement('div');
      div.className = `relative group ${sizeClass} overflow-hidden border border-slate-700/50 photo-wrapper`;
      div.dataset.photoId = photo.id;
      
      div.innerHTML = `
          <img src="${photo.path}" class="w-full h-full object-cover cursor-pointer" onclick="openImageModal('${photo.path}')">
          <button type="button" onclick="removeImage('${itemId}', '${photo.id}')" class="absolute top-0 right-0 ${btnClass} bg-black/50 hover:bg-rose-500 text-white flex items-center justify-center transition-colors backdrop-blur-sm">
              <i class="fas fa-times ${iconSize}"></i>
          </button>
      `;
      
      container.appendChild(div);
      
      // Hide buttons if limit reached
      const currentCount = container.querySelectorAll('.photo-wrapper').length;
      if (currentCount >= 5) {
          if (isMobile) {
              const label = container.parentElement.querySelector('label');
              if (label) label.innerText = `Photos (${currentCount}/5)`;
              const btnGroup = container.parentElement.querySelector('.flex.items-center.gap-2');
              if (btnGroup) btnGroup.style.display = 'none';
          } else {
               const btnGroup = container.parentElement.parentElement.querySelector('.flex.items-center.justify-center.gap-1'); // Adjusted for desktop wrapper
               if (btnGroup) btnGroup.style.display = 'none';
          }
      } else if (isMobile) { // Update count text even if not full
          const label = container.parentElement.querySelector('label');
          if (label) label.innerText = `Photos (${currentCount}/5)`;
      }
  });
}

function removeImage(itemId, photoId) {
  console.log('=== removeImage called ===');
  console.log('Input parameters:', { itemId, photoId });
  console.log('Parameter types:', { itemIdType: typeof itemId, photoIdType: typeof photoId });
  
  if (!confirm('Are you sure you want to delete this photo?')) {
    console.log('User cancelled removal');
    return;
  }
  
  const formData = new FormData();
  formData.append('action', 'remove_image');
  formData.append('item_id', itemId); 
  formData.append('photo_id', photoId);
  
  console.log('FormData entries:');
  for (let pair of formData.entries()) {
    console.log('  ' + pair[0] + ':', pair[1], '(type:', typeof pair[1], ')');
  }

  console.log('Sending fetch request to checklist.php...');
  fetch('checklist.php', {
    method: 'POST',
    body: formData
  })
  .then(response => {
    console.log('Response received. Status:', response.status);
    return response.json();
  })
  .then(data => {
    console.log('Response data:', data);
    if (data.success) {
      console.log('Success! Removing photo from DOM...');
      // Remove element from DOM
      const wrappers = document.querySelectorAll(`.photo-wrapper[data-photo-id="${photoId}"]`);
      console.log('Found', wrappers.length, 'wrapper(s) to remove');
      wrappers.forEach(el => {
          const container = el.parentElement;
          el.remove();
          
          // Check count and restore buttons
          const currentCount = container.querySelectorAll('.photo-wrapper').length;
          const isMobile = container.id.includes('mobile');
          
          if (isMobile) {
              const label = container.parentElement.querySelector('label');
              if (label) label.innerText = `Photos (${currentCount}/5)`;
              if (currentCount < 5) {
                  const btnGroup = container.parentElement.querySelector('.flex.items-center.gap-2');
                  if (btnGroup) btnGroup.style.display = 'flex';
              }
              if (currentCount === 0) {
                  container.innerHTML = '<span class="text-slate-600 text-xs italic">No photos attached</span>';
              }
          } else {
              if (currentCount < 5) {
                  const btnGroup = container.parentElement.parentElement.querySelector('.flex.items-center.justify-center.gap-1');
                  if (btnGroup) btnGroup.style.display = 'flex';
              }
              if (currentCount === 0) {
                   container.innerHTML = '<span class="text-slate-700 text-xs">-</span>';
              }
          }
      });
    } else {
      console.error('Remove failed:', data.error);
      showError(data.error || 'Failed to remove');
    }
  })
  .catch(err => {
    console.error('Error removing photo:', err);
    showError('Error removing photo');
  });
}


// ============================================
// IMAGE MODAL
// ============================================
function openImageModal(path) {
  const modal = document.getElementById("image-modal");
  const img = document.getElementById("modal-image");
  img.src = path;
  modal.classList.add("active");
}

function closeImageModal() {
  document.getElementById("image-modal").classList.remove("active");
}

// ============================================
// FILTERING
// ============================================
const filterArea = document.getElementById("filter-area");
const filterSearch = document.getElementById("filter-search");
const filterBtns = document.querySelectorAll(".filter-btn");

let currentStatusFilter = "all";

filterArea.addEventListener("change", applyFilters);
filterSearch.addEventListener("input", applyFilters);

filterBtns.forEach((btn) => {
  btn.addEventListener("click", function () {
    filterBtns.forEach((b) => b.classList.remove("active"));
    this.classList.add("active");
    currentStatusFilter = this.dataset.filter;
    applyFilters();
  });
});

function applyFilters() {
  const areaFilter = filterArea.value.toLowerCase();
  const searchFilter = filterSearch.value.toLowerCase();

  const items = document.querySelectorAll(".checklist-item");
  const areaSections = document.querySelectorAll(".area-section");

  items.forEach((item) => {
    const itemArea = item.dataset.area.toLowerCase();
    const itemTask = item.dataset.task;
    const isChecked = item.dataset.checked === "1";

    let showByArea = areaFilter === "all" || itemArea === areaFilter;
    let showBySearch = !searchFilter || itemTask.includes(searchFilter);
    let showByStatus =
      currentStatusFilter === "all" ||
      (currentStatusFilter === "checked" && isChecked) ||
      (currentStatusFilter === "unchecked" && !isChecked);

    if (showByArea && showBySearch && showByStatus) {
      item.classList.remove("hidden-by-filter");
    } else {
      item.classList.add("hidden-by-filter");
    }
  });

  // Hide empty area sections
  areaSections.forEach((section) => {
    const visibleItems = section.querySelectorAll(
      ".checklist-item:not(.hidden-by-filter)",
    );
    section.style.display = visibleItems.length > 0 ? "block" : "none";
  });
}

// ============================================
// SUBMIT CHECKLIST
// ============================================
const submitBtn = document.getElementById("submit-btn");
if (submitBtn) {
  submitBtn.addEventListener("click", function () {
    // Validate all items have a shift selected
    const allItems = document.querySelectorAll('.checklist-item[data-item-id]');
    const uniqueItemIds = new Set();
    allItems.forEach(item => uniqueItemIds.add(item.dataset.itemId));
    
    const checkedItems = document.querySelectorAll('.checklist-item[data-checked="1"]');
    const checkedItemIds = new Set();
    checkedItems.forEach(item => checkedItemIds.add(item.dataset.itemId));
    
    const totalItems = uniqueItemIds.size;
    const completedItems = checkedItemIds.size;
    const missingItems = totalItems - completedItems;
    
    if (missingItems > 0) {
      showError(`Please complete all items before submitting. ${missingItems} item(s) remaining.`);
      return;
    }
    
    document.getElementById("confirm-modal").classList.add("active");
  });
}

function closeConfirmModal() {
  document.getElementById("confirm-modal").classList.remove("active");
}

function finalizeChecklist() {
  closeConfirmModal();

  const formData = new FormData();
  formData.append("action", "finalize");

  fetch("checklist.php", {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        document.getElementById("success-modal").classList.add("active");
      } else {
        showError("Failed to submit checklist.");
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      showError("Failed to submit checklist.");
    });
}

// ============================================
// ERROR HANDLING
// ============================================
function showError(message) {
  document.getElementById("error-message").textContent = message;
  document.getElementById("error-modal").classList.add("active");
}

function closeErrorModal() {
  document.getElementById("error-modal").classList.remove("active");
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener("keydown", function (e) {
  if (e.key === "Escape") {
    closeImageModal();
    closeConfirmModal();
    closeErrorModal();
  }
});

// Close modals on overlay click
document
  .getElementById("confirm-modal")
  .addEventListener("click", function (e) {
    if (e.target === this) closeConfirmModal();
  });

document.getElementById("error-modal").addEventListener("click", function (e) {
  if (e.target === this) closeErrorModal();
});

// ============================================
// INITIALIZE
// ============================================
document.addEventListener("DOMContentLoaded", function () {
  updateProgressCount();
});
