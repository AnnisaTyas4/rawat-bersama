document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.getElementById('sidebar');
  const menuButton = document.getElementById('mobileMenu');
  const backdrop = document.getElementById('sidebarBackdrop');
  const closeMenu = () => {
    if (!sidebar || !menuButton || !backdrop) return;
    sidebar.classList.remove('open');
    backdrop.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Buka menu navigasi');
    document.body.classList.remove('menu-open');
  };
  const openMenu = () => {
    if (!sidebar || !menuButton || !backdrop) return;
    sidebar.classList.add('open');
    backdrop.hidden = false;
    menuButton.setAttribute('aria-expanded', 'true');
    menuButton.setAttribute('aria-label', 'Tutup menu navigasi');
    document.body.classList.add('menu-open');
  };
  if (menuButton) menuButton.addEventListener('click', () => {
    sidebar && sidebar.classList.contains('open') ? closeMenu() : openMenu();
  });
  if (backdrop) backdrop.addEventListener('click', closeMenu);
  if (sidebar) sidebar.querySelectorAll('.side-nav a').forEach(link => link.addEventListener('click', closeMenu));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenu(); });
  window.addEventListener('resize', () => { if (window.innerWidth > 760) closeMenu(); });
  document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented || !form.checkValidity()) return;
    const confirmation = form.dataset.confirm;
    if (confirmation && !window.confirm(confirmation)) {
      event.preventDefault();
      return;
    }
    const button = event.submitter || form.querySelector('button[type="submit"],button:not([type])');
    if (button && !button.dataset.keepLabel) {
      button.disabled = true;
      button.textContent = 'Menyimpan…';
    }
  }));
});