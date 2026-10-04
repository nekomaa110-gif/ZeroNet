@once
@push('scripts')
<script>
window.vgToast = function (pesan, tone) {
  var stack = document.querySelector('.flash-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'flash-stack';
    stack.setAttribute('role', 'status');
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);
  }

  var el = document.createElement('div');
  el.className = 'flash-toast ' + (tone || 'ok');
  el.title = 'Klik untuk menutup';
  var teks = document.createElement('span');
  teks.textContent = pesan;
  el.appendChild(teks);
  stack.appendChild(el);

  requestAnimationFrame(function () {
    requestAnimationFrame(function () { el.classList.add('is-in'); });
  });

  var tutup = function () {
    el.classList.remove('is-in');
    setTimeout(function () { el.remove(); }, 420);
  };

  el.addEventListener('click', tutup);
  setTimeout(tutup, (tone === 'err' ? 8000 : 5000));
};
</script>
@endpush
@endonce
