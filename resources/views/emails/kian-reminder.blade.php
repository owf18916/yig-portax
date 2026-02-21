<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            padding: 30px;
        }
        .alert {
            background-color: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-title {
            font-weight: 600;
            color: #92400e;
            margin: 0 0 5px 0;
        }
        .alert-text {
            color: #78350f;
            margin: 0;
            font-size: 14px;
        }
        .case-details {
            background-color: #f3f4f6;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #475569;
            min-width: 150px;
        }
        .detail-value {
            color: #1e293b;
            text-align: right;
            word-break: break-word;
        }
        .amount {
            background-color: #eff6ff;
            padding: 15px;
            border-left: 4px solid #3b82f6;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .amount-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .amount-value {
            font-size: 24px;
            font-weight: 700;
            color: #2563eb;
        }
        .reason-box {
            background-color: #fef2f2;
            border-left: 4px solid #dc2626;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .reason-label {
            font-weight: 600;
            color: #991b1b;
            margin: 0 0 8px 0;
        }
        .reason-text {
            color: #7f1d1d;
            margin: 0;
            font-size: 14px;
        }
        .action-button {
            display: inline-block;
            background-color: #2563eb;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 20px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .action-button:hover {
            background-color: #1e40af;
        }
        .instructions {
            background-color: #f0fdf4;
            border-left: 4px solid #16a34a;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .instructions-title {
            font-weight: 600;
            color: #166534;
            margin: 0 0 8px 0;
        }
        .instructions-text {
            color: #15803d;
            margin: 0;
            font-size: 14px;
            line-height: 1.6;
        }
        .footer {
            background-color: #f9fafb;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }
        .footer-text {
            margin: 0;
        }
        @media (max-width: 600px) {
            .container {
                margin: 10px;
                border-radius: 0;
            }
            .content {
                padding: 20px;
            }
            .detail-row {
                flex-direction: column;
            }
            .detail-label {
                margin-bottom: 5px;
            }
            .detail-value {
                text-align: left;
            }
            .header h1 {
                font-size: 20px;
            }
            .amount-value {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⚠️ KIAN Required</h1>
            <p>Upaya Hukum Keberatan Atas Hasil Penghitungan</p>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Alert -->
            <div class="alert">
                <div class="alert-title">Perhatian</div>
                <div class="alert-text">
                    Hasil penghitungan pada tahap {{ $stageName }} menunjukkan masih ada kerugian. 
                    Anda dimungkinkan untuk mengajukan KIAN.
                </div>
            </div>

            <!-- Case Details -->
            <div class="case-details">
                <h3 style="margin-top: 0; color: #1e293b;">Informasi Kasus</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Kasus:</div>
                    <div class="detail-value">{{ $caseNumber }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jenis Kasus:</div>
                    <div class="detail-value">{{ $caseType }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Tahun Pajak:</div>
                    <div class="detail-value">{{ $caseYear }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Wajib Pajak:</div>
                    <div class="detail-value">{{ $entityName }}</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Tahap Saat Ini:</div>
                    <div class="detail-value">{{ $stageName }}</div>
                </div>
            </div>

            <!-- Amount Summary -->
            <div class="amount">
                <div class="amount-label">Kerugian yang Masih Tertanggung</div>
                <div class="amount-value">{{ $currencyCode }} {{ number_format($lossAmount, 0, ',', '.') }}</div>
            </div>

            <!-- Instructions -->
            <div class="instructions">
                <div class="instructions-title">Langkah Selanjutnya</div>
                <div class="instructions-text">
                    1. Klik tombol "Lihat Kasus" di bawah untuk membuka detail kasus<br>
                    2. Review informasi kerugian yang masih tertanggung<br>
                    3. Klik tombol "Ajukan KIAN" untuk memulai proses pengajuan<br>
                    4. Lengkapi data KIAN sesuai dengan ketentuan yang berlaku<br>
                    5. Submit dokumen pendukung sesuai persyaratan
                </div>
            </div>

            <!-- Action Button -->
            <div style="text-align: center;">
                <a href="{{ $caseUrl }}" class="action-button">Lihat Kasus</a>
            </div>

            <!-- Additional Info -->
            <div style="background-color: #f3f4f6; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                <p style="margin: 0; font-size: 13px; color: #666; line-height: 1.6;">
                    <strong>Catatan:</strong> Email ini dikirimkan secara otomatis oleh sistem PorTax. 
                    Jika Anda memiliki pertanyaan atau memerlukan bantuan, silakan hubungi tim administrasi pajak kami.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="footer-text">
                © {{ date('Y') }} PorTax Management System. Semua hak cipta dilindungi.
            </p>
            <p class="footer-text">
                Email ini dikirimkan kepada Anda karena Anda terkait dengan kasus pajak ini.
            </p>
        </div>
    </div>
</body>
</html>
