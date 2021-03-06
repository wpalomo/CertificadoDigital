<?php
class FirmaElectronica
{
    private $config; ///< Configuración de la firma electrónica
    private $certs; ///< Certificados digitales de la firma
    private $data; ///< Datos del certificado digial

    /**
     * Constructor para la clase: crea configuración y carga certificado digital
     *
     * Si se desea pasar una configuración específica para la firma electrónica
     * se debe hacer a través de un arreglo con los índices file y pass, donde
     * file es la ruta hacia el archivo .p12 que contiene tanto la clave privada
     * como la pública y pass es la contraseña para abrir dicho archivo.
     * Ejemplo:
     *
     * \code{.php}
     *   $firma_config = ['file'=>'/ruta/al/certificado.p12', 'pass'=>'contraseña'];
     *   $firma = new FirmaElectronica($firma_config);
     * \endcode
     *
     * También se permite que en vez de pasar la ruta al certificado p12 se pase
     * el contenido del certificado, esto servirá por ejemplo si los datos del
     * archivo están almacenados en una base de datos. Ejemplo:
     *
     * \code{.php}
     *   $firma_config = ['data'=>file_get_contents('/ruta/al/certificado.p12'), 'pass'=>'contraseña'];
     *   $firma = new FirmaElectronica($firma_config);
     * \endcode
     *
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'file' => null,
            'pass' => null,
            'data' => null,
            'wordwrap' => 64,
        ], $config);
        // cargar firma electrónica desde el contenido del archivo .p12 si no
        // se pasaron como datos del arreglo de configuración
        if (!$this->config['data'] and $this->config['file']) {
            if (is_readable($this->config['file'])) {
                $this->config['data'] = file_get_contents($this->config['file']);
            } else {
                return $this->error('Archivo de la firma electrónica '.basename($this->config['file']).' no puede ser leído');
            }
        }
        // leer datos de la firma electrónica
        if ($this->config['data'] and openssl_pkcs12_read($this->config['data'], $this->certs, $this->config['pass'])===false) {
            return $this->error('No fue posible leer los datos de la firma electrónica (verificar la contraseña)');
        }
        $this->data = openssl_x509_parse($this->certs['cert']);
        // quitar datos del contenido del archivo de la firma
        unset($this->config['data']);
    }
	
	/**
     * Método que obtiene el módulo de la clave privada
     * @return Módulo en base64
     */
    public function getModulus()
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->certs['pkey']));
        return wordwrap(base64_encode($details['rsa']['n']), $this->config['wordwrap'], "\n", true);
    }

    /**
     * Método que obtiene el exponente público de la clave privada
     * @return Exponente público en base64
     */
    public function getExponent()
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->certs['pkey']));
        return wordwrap(base64_encode($details['rsa']['e']), $this->config['wordwrap'], "\n", true);
    }

    /**
     * Método que entrega el certificado de la firma
     * @return Contenido del certificado, clave pública del certificado digital, en base64
     */
    public function getCertificate($clean = false)
    {
        if ($clean) {
            return trim(str_replace(
                ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
                '',
                $this->certs['cert']
            ));
        } else {
            return $this->certs['cert'];
        }
    }
	
	/**
     * Método para realizar la firma de datos
     * @param data Datos que se desean firmar
     * @param signature_alg Algoritmo que se utilizará para firmar (por defect SHA1)
     * @return Firma digital de los datos en base64 o =false si no se pudo firmar
     */
    public function sign($data, $signature_alg = OPENSSL_ALGO_SHA1)
    {
        $signature = null;
        if (openssl_sign($data, $signature, $this->certs['pkey'], $signature_alg)==false) {
            return $this->error('No fue posible firmar los datos');
        }
        return base64_encode($signature);
    }

    /**
     * Método que firma un XML utilizando RSA y SHA1
     *
     * Referencia: http://www.di-mgt.com.au/xmldsig2.html
     *
     * @param xml Datos XML que se desean firmar
     * @param reference Referencia a la que hace la firma
     * @return XML firmado o =false si no se pudo fimar
     */
    public function signXML($xmlName, $reference = '', $tag = null, $xmlns_xsi = false, $replaceSignature = true)
    {
		$xml_data = file_get_contents($xmlName);
        $doc = new XML();
        $doc->loadXML($xml_data);
        if (!$doc->documentElement) {
            return $this->error('No se pudo obtener el documentElement desde el XML a firmar (posible XML mal formado)');
        }
        // crear nodo para la firma
        $Signature = $doc->importNode((new XML())->generate([
            'Signature' => [
                '@attributes' => [
                    'xmlns' => 'http://www.w3.org/2000/09/xmldsig#',
                ],
                'SignedInfo' => [
                    '@attributes' => [
                        'xmlns' => 'http://www.w3.org/2000/09/xmldsig#',
                        'xmlns:xsi' => $xmlns_xsi ? 'http://www.w3.org/2001/XMLSchema-instance' : false,
                    ],
                    'CanonicalizationMethod' => [
                        '@attributes' => [
                            'Algorithm' => 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
                        ],
                    ],
                    'SignatureMethod' => [
                        '@attributes' => [
                            'Algorithm' => 'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
                        ],
                    ],
                    'Reference' => [
                        '@attributes' => [
                            'URI' => $reference,
                        ],
                        'Transforms' => [
                            'Transform' => [
                                '@attributes' => [
                                    'Algorithm' => 'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                                ],
                            ],
                        ],
                        'DigestMethod' => [
                            '@attributes' => [
                                'Algorithm' => 'http://www.w3.org/2000/09/xmldsig#sha1',
                            ],
                        ],
                        'DigestValue' => null,
                    ],
                ],
                'SignatureValue' => null,
                'KeyInfo' => [
                    'KeyValue' => [
                        'RSAKeyValue' => [
                            'Modulus' => null,
                            'Exponent' => null,
                        ],
                    ],
                    'X509Data' => [
                        'X509Certificate' => null,
                    ],
                ],
            ],
        ])->documentElement, true);
        // calcular DigestValue
        if ($tag) {
            $item = $doc->documentElement->getElementsByTagName($tag)->item(0);
            if (!$item) {
                return $this->error('No fue posible obtener el nodo con el tag '.$tag);
            }
            $digest = base64_encode(sha1($item->C14N(), true));
        } else {
            $digest = base64_encode(sha1($doc->C14N(), true));
        }
        $Signature->getElementsByTagName('DigestValue')->item(0)->nodeValue = $digest;
        // calcular SignatureValue
        $SignedInfo = $doc->saveHTML($Signature->getElementsByTagName('SignedInfo')->item(0));
        $firma = $this->sign($SignedInfo);
        if (!$firma)
            return false;
        $signature = wordwrap($firma, $this->config['wordwrap'], "\n", true);
        // reemplazar valores en la firma de
        $Signature->getElementsByTagName('SignatureValue')->item(0)->nodeValue = $signature;
        $Signature->getElementsByTagName('Modulus')->item(0)->nodeValue = $this->getModulus();
        $Signature->getElementsByTagName('Exponent')->item(0)->nodeValue = $this->getExponent();
        $Signature->getElementsByTagName('X509Certificate')->item(0)->nodeValue = $this->getCertificate(true);        
		
		if($replaceSignature) {
			// Reemplazar el nodo signature por el nuevo firmado
			$signatureNode = $doc->documentElement->getElementsByTagName('Signature')->item(0);
			$signatureNode->parentNode->replaceChild($Signature, $signatureNode);
		}
		else {
			// Agregar y entregar firma
			$doc->documentElement->appendChild($Signature);
		}
		$doc->save($xmlName);
    }
}