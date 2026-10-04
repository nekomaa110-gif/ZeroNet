  <script nonce="{{ $nonce }}">
    document.getElementById('tplPick').addEventListener('change', function () {
      const url = new URL(window.location.href);
      url.searchParams.set('t', this.value);
      window.location.href = url.toString();
    });

    document.getElementById('printBtn').addEventListener('click', function () {
      const ids = @json($vouchers->pluck('id'));

      if (ids.length) {
        fetch(@json(route('vouchers.mark-printed', $batch)), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
          },
          body: JSON.stringify({ ids: ids }),
          keepalive: true,
        }).catch(function () {});
      }

      window.print();
    });
  </script>
