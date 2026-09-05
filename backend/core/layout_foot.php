    </div>
  </div>
</div>

<script>
function toggleSidebar(open){
  document.getElementById('sidebar').classList.toggle('open', open);
  document.getElementById('sidebarBg').style.display = open ? 'block' : 'none';
}
function openModal(id){ document.getElementById(id).classList.add('show'); }
function closeModal(id){ document.getElementById(id).classList.remove('show'); }
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-bg.show').forEach(m => m.classList.remove('show'));
});

// PWA: register service worker so Android/Chrome offers "Install app"
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js').catch(() => {});
  });
}
</script>
</body>
</html>
