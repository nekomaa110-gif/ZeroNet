@php
    $price    = $package->priceLabel();
    $validity = $package->validityLabel();
    $waPlan   = 'https://wa.me/'.config('site.whatsapp').'?text='
        .rawurlencode('Halo ZeroNet, saya mau tanya paket "'.$package->customerName().'".');
@endphp

<article class="price-row">
  <div class="pr-name">
    <h3>{{ $package->customerName() }}</h3>
    @if ($package->public_description)
      <p>{{ $package->public_description }}</p>
    @endif
  </div>
  <div class="pr-speed">
    {{ $package->speed_label ?: 'Sesuai area' }}
    <small>Kecepatan</small>
  </div>
  <div>
    @if ($price)
      <div class="pr-price">{{ $price }}<small>{{ $validity ? 'Masa aktif '.$validity : 'Harga paket' }}</small></div>
    @elseif ($validity)
      <div class="pr-price">{{ $validity }}<small>Masa aktif</small></div>
    @endif
  </div>
  <div class="pr-cta">
    <a href="{{ $waPlan }}" class="btn btn-primary" rel="noopener" target="_blank">Pesan paket</a>
  </div>
</article>
