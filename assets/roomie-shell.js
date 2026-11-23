const roomieAccountMenu = document.querySelector('.roomie-shell .roomie-shell-menu');

if (roomieAccountMenu) {
  const trigger = roomieAccountMenu.querySelector('summary');

  document.addEventListener('keydown', event => {
    if (!roomieAccountMenu.open || event.key !== 'Escape' || event.defaultPrevented) return;
    event.preventDefault();
    roomieAccountMenu.open = false;
    trigger.focus();
  });

  function dismissOnOutside(event) {
    if (roomieAccountMenu.open && !roomieAccountMenu.contains(event.target)) {
      roomieAccountMenu.open = false;
    }
  }

  document.addEventListener('focusin', dismissOnOutside);
  document.addEventListener('click', dismissOnOutside);
}
