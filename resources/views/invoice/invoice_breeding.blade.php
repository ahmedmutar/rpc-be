<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Nota Breeding</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            color: #000;
            padding: 15px;
            margin: 0;
        }

        .header {
            margin-bottom: 15px;
        }

        .header-title {
            text-align: center;
            margin-bottom: 10px;
        }

        .header-title h3 {
            margin: 0;
            font-size: 12px;
            text-decoration: underline;
        }

        .header-content {
            display: flex;
            align-items: center;
        }

        .alamat-section {
            flex: 1;
        }

        .alamat-info {
            font-size: 8px;
            line-height: 1.4;
            text-align: left;
        }

        .alamat-info p {
            margin: 2px 0;
            padding: 0;
        }

        .location-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        .location-table td {
            /* border: 1px solid #000; */
            padding: 3px;
            vertical-align: top;
        }

        .logo-cell {
            width: 15%;
            text-align: center;
        }

        .logo-text {
            /* border: 1px solid #000; */
            padding: 2px;
            font-size: 8px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .logo {
            width: 70px;
            height: auto;
        }

        .locations-cell {
            width: 85%;
            line-height: 1.3;
        }

        .location-row {
            margin-bottom: 2px;
            font-size: 7px;
        }

        .locations-container {
            display: flex;
            gap: 8px;
        }

        .location-column {
            flex: 1;
            padding: 0 2px;
        }

        .nota-header {
            text-align: center;
            margin: 15px 0 10px 0;
            position: relative;
        }

        .nota-date {
            position: absolute;
            left: 0;
            font-size: 11px;
        }

        .nota-title {
            font-size: 14px;
            font-weight: bold;
            display: inline-block;
        }

        .nota-number {
            position: absolute;
            right: 0;
            font-size: 11px;
        }

        .nota-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }

        .left {
            display: flex;
            flex-direction: column;
        }

        .nota-date {
            font-size: 10px;
            color: #333;
        }

        .nota-title {
            font-weight: bold;
            font-size: 16px;
        }

        .nota-number {
            font-size: 12px;
        }


        .customer-info {
            margin: 10px 0;
        }

        .customer-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .customer-info td {
            padding: 2px 0;
            font-size: 11px;
        }

        .services-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .services-table th,
        .services-table td {
            border: 1px solid #000;
            padding: 4px;
            font-size: 10px;
            text-align: center;
        }

        .services-table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        .services-table .service-name {
            text-align: center;
            width: 30%;
        }

        .services-table .item-name {
            text-align: left;
        }

        .services-table .animal-type {
            width: 20%;
        }

        .services-table .quantity {
            width: 10%;
        }

        .services-table .price {
            width: 20%;
        }

        .services-table .total {
            width: 20%;
        }

        .groomer-info {
            margin: 15px 0;
            font-size: 11px;
        }

        .groomer-line {
            margin: 3px 0;
        }

        .notes {
            margin: 15px 0;
            font-size: 9px;
        }

        .notes strong {
            font-size: 10px;
        }

        .payment-info {
            margin: 15px 0;
            font-size: 9px;
        }

        .signature-section {
            margin-top: 20px;
            text-align: right;
            font-size: 11px;
        }

        .signature-box {
            margin-top: 30px;
            text-align: center;
        }

        .dotted-line {
            border-bottom: 1px dotted #000;
            display: inline-block;
            min-width: 200px;
        }

        .total-row {
            font-weight: bold;
            text-align: right;
        }

        hr {
            border: none;
            border-top: 1px solid #000;
            margin: 10px 0;
        }

        img {
            display: block;
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-title">
            <h3>ALAMAT RADHIYAN PET AND CARE</h3>
        </div>

        <div class="header-content">
            <table class="location-table" width="100%">
                <tr>
                    <td class="logo-cell" width="25%" valign="top" align="center">
                        <div style="padding: 1px; font-size: 8px; font-weight: bold; margin-bottom: 5px;">
                            RADHIYAN PET AND CARE
                        </div>
                        <img src="{{ public_path() . '/asset/logo-rpc-full-min.webp' }}" alt="Logo" style="width: 120px;">
                    </td>

                    <td class="locations-cell" width="75%">
                        <table width="100%">
                            <tr>
                                @php
                                $total = count($locations);
                                $part = ceil($total / 3);
                                $firstThird = array_slice($locations, 0, $part);
                                $secondThird = array_slice($locations, $part, $part);
                                $thirdThird = array_slice($locations, $part * 2);
                                @endphp
                                <td width="30%" valign="top">
                                    @foreach($firstThird as $location)
                                    <div style="font-size: 6px; line-height: 1.4; margin-bottom: 2px;">
                                        <strong>{{ $location['name'] }}</strong> - {{ $location['description'] }} - {{ $location['phone'] }}
                                    </div>
                                    @endforeach
                                </td>
                                <td width="30%" valign="top">
                                    @foreach($secondThird as $location)
                                    <div style="font-size: 6px; line-height: 1.4; margin-bottom: 2px;">
                                        <strong>{{ $location['name'] }}</strong> - {{ $location['description'] }} - {{ $location['phone'] }}
                                    </div>
                                    @endforeach
                                </td>
                                <td width="40%" valign="top">
                                    @foreach($thirdThird as $location)
                                    <div style="font-size: 6px; line-height: 1.4; margin-bottom: 2px;">
                                        <strong>{{ $location['name'] }}</strong> - {{ $location['description'] }} - {{ $location['phone'] }}
                                    </div>
                                    @endforeach

                                    <div style="font-size: 7px; margin-top: 5px;">
                                        <strong>Call Center:</strong> 081312245500
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

        </div>
    </div>

    <hr>

    <table width="100%" style="margin-bottom: 10px;">
        <tr>
            <!-- Kolom kiri: Tanggal -->
            <td style="font-size: 10px; text-align: left; width: 30%;">
                {{ $nota_date }}
            </td>

            <!-- Kolom tengah: Judul -->
            <td style="font-weight: bold; font-size: 14px; text-align: center; width: 40%;">
                NOTA PET CLINIC
            </td>

            <!-- Kolom kanan: No Nota -->
            <td style="font-size: 10px; text-align: right; width: 30%; white-space: nowrap;">
                No.Nota: {{ $no_nota }}
            </td>
        </tr>
    </table>


    <div class="customer-info">
        <table>
            <tr>
                <td style="width: 150px;">Nomor Kartu</td>
                <td>: <span>{{ $member_no }}</span></td>
            </tr>
            <tr>
                <td>Nama Pemilik</td>
                <td>: <span>{{ $customer_name }}</span></td>
            </tr>
            <tr>
                <td>No.Telp (WA)</td>
                <td>: <span>{{ $phone_number }}</span></td>
            </tr>
            <tr>
                <td>Jam Kedatangan</td>
                <td>: <span>{{ $arrival_time }}</span></td>
            </tr>
        </table>
    </div>

    <table class="services-table">
        <thead>
            <tr>
                <th class="service-name">ITEM</th>
                <th class="promo">JUMLAH</th>
                <th class="quantity">BONUS</th>
                <th class="quantity">DISKON</th>
                <th class="price">HARGA</th>
                <th class="total">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($details as $item)

            @if ($item['promoId'] === null)

            @isset($item['serviceId'])
            <tr>
                <td class="item-name">{{ $item['item_name'] }}</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ 0 }}</td>
                <td>{{ 0 }}</td>
                <td>{{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>

            @elseif(isset($item['productId']))
            <tr>
                <td class="item-name">{{ $item['item_name']}}</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ 0 }}</td>
                <td>{{ 0 }}</td>
                <td>{{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>

            @endisset

            @else
            @if ($item['promoCategory'] === 'freeItem')

            <tr>
                <td class="item-name">{{ $item['item_name'] }}</td>
                <td>{{ $item['quantity_buy'] }}</td>
                <td>{{ $item['quantity_free'] }}</td>
                <td>{{ number_format($item['discount'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>

            @elseif($item['promoCategory'] === 'bundle')

            <tr>
                <td class="item-name">{{ $item['item_name'] }}
                    <ul>
                        @foreach($item['included_items'] as $bundleItem)
                        <li>{{ $bundleItem['item_name'] }}</li>
                        @endforeach
                    </ul>
                </td>
                <td>{{ 1 }}</td>
                <td>{{ 0 }}</td>
                <td>{{ number_format(0, 0, ',', '.') }}</td>
                <td>{{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>

            @elseif($item['promoCategory'] === 'discount')

            <tr>
                <td class="item-name">{{ $item['item_name'] }} (Promo: Diskon Produk)</td>
                <td>{{ $item['quantity'] }}</td>
                <td>{{ 0 }}</td>
                <td>{{ number_format($item['discount'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['unit_price'], 0, ',', '.') }}</td>
                <td>{{ number_format($item['total'], 0, ',', '.') }}</td>
            </tr>

            @endif

            @endif

            @endforeach
            <tr>
                <td colspan="5" class="total-row">TOTAL</td>
                <td>{{ number_format($subtotal, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="5" class="total-row">DISKON</td>
                <td>{{ number_format($total_discount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="5" class="total-row">DEPOSIT</td>
                <td>-</td>
            </tr>
            <tr>
                <td colspan="5" class="total-row">TOTAL TAGIHAN</td>
                <td>{{ number_format($total_payment, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>


    <!-- <div class="groomer-info">
        <div class="groomer-line">
            Nama Groomer : <span class="dotted-line" style="min-width: 250px;"></span> (_____ ekor)
        </div>
        <div class="groomer-line">
            Nama Groomer : <span class="dotted-line" style="min-width: 250px;"></span> (_____ ekor)
        </div>
    </div> -->

    <div class="notes">
        <strong>Catatan:</strong><br>
        <!-- -Kumpulkan nota bukti grooming & penitipan (*tunjukkan kepada pegawai kasir)<br> -->
        -Khusus VIP Member diskon 10%<br>
        <!-- -Penitipan minimal 10 hari, gratis grooming sehat 1x -->
    </div>

    <table style="width: 100%; font-size: 12px; line-height: 1.4; border-collapse: collapse;">
        <tr>
            <!-- Kolom kiri: Info Pembayaran -->
            <td style="width: 65%; vertical-align: top;">
                <div class="payment-info">
                    <strong>Pembayaran via transfer ke:</strong><br><br>

                    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                        <tr>
                            <td style="width: 130px; vertical-align: top; font-weight: bold;">No. Rek BCA</td>
                            <td style="vertical-align: top; font-weight: bold;">599-096-0005</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">a.n</td>
                            <td style="padding-bottom: 4px; font-weight: bold;">Radhiyan Fadiar Sahistya</td>
                        </tr>
                        <tr>
                            <td style="vertical-align: top;">Cabang</td>
                            <td style="padding-bottom: 12px;">
                                Kp. Gading, Pulogebang, Tanjung Duren, Buaran, Sukmajaya, Sawangan, Hankam Pondokgede, Lippo Cikarang, Kalangtenah Karawaci Ketintang Surabaya, Waru Sidoarjo, Kenten Palembang
                            </td>
                        </tr>

                        <tr>
                            <td style="width: 130px; vertical-align: top; font-weight: bold;">No. Rek BCA</td>
                            <td style="vertical-align: top; font-weight: bold;">599-093-0009</td>
                        </tr>
                        <tr>
                            <td style="font-weight: bold;">a.n</td>
                            <td style="padding-bottom: 4px; font-weight: bold;">Dharmawijaya Widyatama</td>
                        </tr>
                        <tr>
                            <td style="vertical-align: top;">Cabang</td>
                            <td style="padding-bottom: 12px;">Condet & Rawamangun</td>
                        </tr>
                    </table>

                    <div style="margin-top: 10px; font-weight: bold;">
                        Pembayaran akan dianggap sah setelah masuk ke rekening yang ada di atas
                    </div>
                </div>
            </td>

            <!-- Kolom kanan: Tanda tangan -->
            <td style="width: 35%; vertical-align: top; padding-top: 20px;">
                <div class="signature-section" style="width: 100%; text-align: center;">
                    Mengetahui,<br><br><br><br>
                    ( _________________ )<br>
                    <strong>Kasir</strong>
                </div>
            </td>
        </tr>
    </table>

</body>

</html>
