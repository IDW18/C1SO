    </div>
  </main>
</div>

<script>

  document.addEventListener('click', function(e){
    const menu = document.querySelector('.account-menu');
    const dropdown = document.getElementById('accountDropdown');
    if (menu && dropdown && !menu.contains(e.target)) {
      dropdown.classList.remove('open');
    }
  });

  function showToast(message, duration) {
    duration = duration || 3600;
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(function(){ toast.classList.add('show'); });
    setTimeout(function(){
      toast.classList.remove('show');
      setTimeout(function(){ toast.remove(); }, 300);
    }, duration);
  }

  document.addEventListener('submit', function(e){
    var form = e.target;
    var btn = form.querySelector('button.btn-primary[type="submit"], button.btn-primary:not([type])');
    if (!btn || btn.disabled) return;
    var loadingText = btn.getAttribute('data-loading-text') || 'Saving...';
    btn.dataset.originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('is-loading');
    btn.innerHTML = '<span class="btn-spinner"></span>' + loadingText;
  });
</script>
</body>
</html>
