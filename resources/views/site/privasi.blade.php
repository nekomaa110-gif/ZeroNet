@extends('site.layouts.app')

@section('title', 'Kebijakan Privasi · '.config('site.brand'))
@section('meta_description', 'Kebijakan privasi '.config('site.brand').': data apa yang dikumpulkan, untuk apa dipakai, dengan siapa dibagikan, dan bagaimana pelanggan bisa memintanya dihapus.')

@section('content')

  <section class="section page-top">
    <div class="wrap">
      <div class="section-head">
        <span class="eyebrow">Legal</span>
        <h2>Kebijakan privasi</h2>
        <p>Berlaku untuk layanan internet {{ config('site.brand') }}, portal pelanggan, dan notifikasi WhatsApp.</p>
      </div>

      <div class="prose">

        <div>
          <h3>Data yang kami kumpulkan</h3>
          <p>Hanya yang dibutuhkan untuk menjalankan layanan:</p>
          <ul>
            <li><b>Identitas akun</b>: nama, nomor WhatsApp, dan alamat pemasangan.</li>
            <li><b>Kredensial layanan</b>: username dan sandi akun WiFi Anda.</li>
            <li><b>Catatan pemakaian</b>: waktu mulai dan selesai sesi koneksi, alamat IP yang diberikan, serta jumlah data terpakai. Kami tidak menyimpan riwayat situs yang Anda buka.</li>
            <li><b>Data penagihan</b>: tagihan, tanggal jatuh tempo, status pembayaran, dan bukti transfer yang Anda unggah.</li>
          </ul>
        </div>

        <div>
          <h3>Untuk apa dipakai</h3>
          <ul>
            <li>Mengautentikasi perangkat Anda supaya bisa terhubung ke jaringan.</li>
            <li>Menerbitkan tagihan dan mencatat pembayaran.</li>
            <li>Mengirim pengingat perpanjangan, tagihan, dan konfirmasi pembayaran lewat WhatsApp ke nomor yang Anda daftarkan.</li>
            <li>Menangani keluhan dan gangguan teknis.</li>
          </ul>
          <p>Kami tidak memakai data Anda untuk iklan dan tidak menjualnya ke pihak mana pun.</p>
        </div>

        <div>
          <h3>Dengan siapa dibagikan</h3>
          <p>
            Notifikasi kami kirim melalui layanan WhatsApp milik Meta, sehingga nomor WhatsApp dan isi pesan
            notifikasi diproses oleh Meta sebagai penyedia layanan pengiriman pesan. Selain itu, data Anda tidak
            dibagikan ke pihak ketiga, kecuali bila diwajibkan oleh hukum atau permintaan resmi aparat yang sah.
          </p>
        </div>

        <div>
          <h3>Berapa lama disimpan</h3>
          <p>
            Data akun dan penagihan disimpan selama Anda berlangganan dan hingga dua tahun setelah berhenti,
            untuk keperluan pembukuan dan penyelesaian sengketa tagihan. Catatan sesi koneksi disimpan lebih
            singkat, secukupnya untuk penanganan gangguan.
          </p>
        </div>

        <div>
          <h3>Hak Anda</h3>
          <p>
            Anda berhak meminta salinan data Anda, memperbaiki data yang keliru, berhenti menerima notifikasi
            WhatsApp, atau meminta data Anda dihapus setelah berhenti berlangganan. Hubungi kami lewat kontak di
            bawah dan permintaan akan kami proses.
          </p>
          <p>
            Perlu dicatat: menghapus data akun berarti layanan tidak bisa dilanjutkan, karena autentikasi jaringan
            membutuhkan data tersebut.
          </p>
        </div>

        <div>
          <h3>Keamanan</h3>
          <p>
            Akses ke data pelanggan dibatasi hanya untuk administrator layanan. Portal pelanggan dan panel
            administrasi berjalan di atas koneksi terenkripsi.
          </p>
        </div>

        <div>
          <h3>Kontak</h3>
          <p>
            Pertanyaan atau permintaan terkait data pribadi bisa disampaikan ke
            @if (config('site.email'))
              <a href="mailto:{{ config('site.email') }}">{{ config('site.email') }}</a>
              atau
            @endif
            WhatsApp <a href="https://wa.me/{{ config('site.whatsapp') }}">{{ config('site.whatsapp') }}</a>.
          </p>
        </div>

      </div>
    </div>
  </section>

@endsection
