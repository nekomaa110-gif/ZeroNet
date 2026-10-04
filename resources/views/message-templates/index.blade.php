@extends('layouts.app')

@section('title', 'Template Pesan')
@section('page-title', 'Template Pesan')

@section('page-class', 'pg-message-templates-index')

@section('content')

  <header class="page-head">
    <div>
      <h2>Template Pesan WhatsApp</h2>
      <p>Ubah teks pesan. Tulisan dalam kurung kurawal seperti <span class="mono">{username}</span> diganti sistem dengan data pelanggan saat pesan dikirim.</p>
    </div>
    <div class="head-actions">
      <a href="{{ route('whatsapp.index') }}" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        WhatsApp Gateway
      </a>
    </div>
  </header>

  @if($errors->any())
    <div class="mb-4"><x-admin.alert type="error" :message="$errors->first()"/></div>
  @endif

  <div class="tpl-search">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="20" y1="20" x2="16.65" y2="16.65"/></svg>
    <input type="search" id="tpl-search" class="input" autocomplete="off"
           placeholder="Cari template (nama, keterangan, atau isi pesan)…">
    <button type="button" id="tpl-search-clear" class="tpl-search-clear" hidden aria-label="Hapus pencarian">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <p class="tpl-search-count" id="tpl-search-count" hidden></p>

  <div class="card mb-4" id="tpl-empty" hidden>
    <div class="card-pad" style="text-align:center; color:var(--text-2); font-size:13px;">
      Tidak ada template yang cocok dengan pencarianmu.
    </div>
  </div>

  @foreach($groups as $groupKey => $group)
    @continue(empty($group['items']))

    <div class="card tpl-group mb-4">
      <div class="card-head">
        <h3>{{ $group['label'] }}</h3>
        <p style="margin:2px 0 0; color:var(--text-2); font-size:12.5px;">{{ $group['hint'] }}</p>
      </div>

      <div class="card-pad" style="display:flex; flex-direction:column; gap:10px;">
        @foreach($group['items'] as $key => $tpl)
          @php
            $sample = \App\Services\MessageTemplateService::sampleVars($key);
            $openByDefault = session('open_template') === $key || (old('body') && old('_key') === $key);
          @endphp

          <div class="tpl-item"
               data-search="{{ \Illuminate\Support\Str::lower($tpl['label'] . ' ' . $tpl['description'] . ' ' . $key . ' ' . $tpl['body']) }}"
               x-data="tplEditor({{ Js::from([
                'key'    => $key,
                'body'   => $tpl['body'],
                'sample' => $sample,
                'open'   => (bool) $openByDefault,
              ]) }})">

            <button type="button" class="tpl-head" @click="open = !open">
              <div class="tpl-head-text">
                <span class="tpl-label">
                  {{ $tpl['label'] }}
                  @if($tpl['customized'])
                    <span class="tpl-badge tpl-badge-edit">Diubah</span>
                  @else
                    <span class="tpl-badge">Bawaan</span>
                  @endif
                </span>
                <span class="tpl-desc">{{ $tpl['description'] }}</span>
              </div>
              <svg class="tpl-caret" :style="open ? 'transform:rotate(180deg)' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>

            <div x-show="open" x-cloak class="tpl-body">
              <form method="POST" action="{{ route('message-templates.update', $key) }}" id="tpl-save-{{ $key }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_key" value="{{ $key }}">

                <div class="field">
                  <label for="body-{{ $key }}">Isi Pesan</label>
                  <textarea id="body-{{ $key }}" name="body" class="input tpl-textarea" rows="12"
                            x-ref="body" x-model="body" required maxlength="4000"></textarea>
                  <small class="hint">
                    <span x-text="body.length"></span>/4000 karakter.
                    Format WhatsApp berlaku: <span class="mono">*tebal*</span>, <span class="mono">_miring_</span>.
                  </small>
                </div>

                <div class="field">
                  <label>Placeholder yang Tersedia</label>
                  <div class="tpl-chips">
                    @foreach(\App\Services\MessageTemplateService::placeholdersOf($key) as $ph)
                      <button type="button" class="tpl-chip" @click="insert('{{ '{' . $ph . '}' }}')"
                              title="{{ $meta[$ph][0] ?? $ph }} (klik untuk menyisipkan)">
                        <span class="mono">{{ '{' . $ph . '}' }}</span>
                        <span class="tpl-chip-desc">{{ $meta[$ph][0] ?? '' }}</span>
                      </button>
                    @endforeach
                  </div>
                  <small class="hint">Klik untuk menyisipkan di posisi kursor. Nama yang salah ketik akan tampil apa adanya di pesan.</small>
                </div>

                <div class="field">
                  <label>Pratinjau <span style="color:var(--text-3);font-weight:400;font-size:11px;">(dengan data contoh)</span></label>
                  <div class="tpl-preview" x-text="preview()"></div>
                </div>

              </form>

              @if($tpl['customized'])
                <form method="POST" action="{{ route('message-templates.reset', $key) }}" id="tpl-reset-{{ $key }}"
                      data-confirm="Kembalikan template ini ke teks bawaan? Perubahanmu akan hilang."
                      data-confirm-title="Kembalikan ke Bawaan"
                      data-confirm-action="Kembalikan"
                      data-confirm-variant="warning">
                  @csrf
                </form>
              @endif

              <div class="form-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" form="tpl-save-{{ $key }}">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  Simpan
                </button>

                @if($tpl['customized'])
                  <button type="submit" class="btn" form="tpl-reset-{{ $key }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                    Kembalikan ke Bawaan
                  </button>
                @endif
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endforeach

@endsection


@push('scripts')
<script>
  (function () {
    const box    = document.getElementById('tpl-search');
    const clear  = document.getElementById('tpl-search-clear');
    const count  = document.getElementById('tpl-search-count');
    const empty  = document.getElementById('tpl-empty');
    const items  = Array.from(document.querySelectorAll('.tpl-item'));
    const groups = Array.from(document.querySelectorAll('.tpl-group'));

    if (!box) return;

    function apply() {
      const terms = box.value.toLowerCase().split(/\s+/).filter(Boolean);
      let shown = 0;

      for (const item of items) {
        const hay = item.dataset.search || '';
        const hit = terms.every(t => hay.includes(t));
        item.hidden = !hit;
        if (hit) shown++;
      }

      for (const group of groups) {
        group.hidden = !group.querySelector('.tpl-item:not([hidden])');
      }

      clear.hidden = terms.length === 0;
      empty.hidden = terms.length === 0 || shown > 0;

      count.hidden = terms.length === 0;
      count.textContent = shown + ' dari ' + items.length + ' template cocok.';
    }

    box.addEventListener('input', apply);
    box.addEventListener('keydown', e => {
      if (e.key === 'Escape') { box.value = ''; apply(); }
    });
    clear.addEventListener('click', () => { box.value = ''; apply(); box.focus(); });
  })();

  function tplEditor({ key, body, sample, open }) {
    return {
      key, body, sample, open,

      insert(token) {
        const el = this.$refs.body;
        const start = el.selectionStart ?? this.body.length;
        const end = el.selectionEnd ?? this.body.length;

        this.body = this.body.slice(0, start) + token + this.body.slice(end);

        this.$nextTick(() => {
          el.focus();
          el.setSelectionRange(start + token.length, start + token.length);
        });
      },

      preview() {
        let out = this.body;
        for (const [name, value] of Object.entries(this.sample)) {
          out = out.replaceAll('{' + name + '}', value);
        }
        return out;
      },
    };
  }
</script>
@endpush
