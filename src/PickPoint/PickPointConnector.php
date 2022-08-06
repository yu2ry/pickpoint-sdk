<?php

namespace PickPointSdk\PickPoint;

use DateTime;
use GuzzleHttp\Client;
use PickPointSdk\Components\State;
use PickPointSdk\Components\Invoice;
use PickPointSdk\Components\TariffPrice;
use PickPointSdk\Components\CourierCall;
use PickPointSdk\Components\PackageSize;
use PickPointSdk\Components\InvoiceValidator;
use PickPointSdk\Contracts\DeliveryConnector;
use PickPointSdk\Exceptions\ValidateException;
use PickPointSdk\Components\SenderDestination;
use PickPointSdk\Components\ReceiverDestination;
use PickPointSdk\Exceptions\PickPointMethodCallException;

class PickPointConnector implements DeliveryConnector
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var PickPointConf
     */
    private $pickPointConf;

    /**
     * @var SenderDestination
     */
    private $senderDestination;

    /**
     * @var PackageSize
     */
    private $defaultPackageSize;

    /**
     * PickPointConnector constructor.
     * @param PickPointConf $pickPointConf
     * @param SenderDestination|null $senderDestination
     * @param PackageSize|null $packageSize
     * @param Client|null $guzzleClient
     */
    public function __construct(
        PickPointConf $pickPointConf,
        SenderDestination $senderDestination,
        PackageSize $packageSize = null,
        Client $guzzleClient = null
    ) {
        $this->client = $guzzleClient ?: new Client();
        $this->pickPointConf = $pickPointConf;
        $this->senderDestination = $senderDestination;
        $this->defaultPackageSize = $packageSize;
    }

    /**
     * @return PackageSize
     */
    public function getDefaultPackageSize(): PackageSize
    {
        return $this->defaultPackageSize;
    }

    /**
     * @return SenderDestination
     */
    public function getSenderDestination(): SenderDestination
    {
        return $this->senderDestination;
    }

    /**
     * @return string
     * @throws PickPointMethodCallException
     */
    private function auth()
    {
        $loginUrl = $this->pickPointConf->getHost() . '/login';

        try {
            $request = $this->client->post($loginUrl, [
                'json' => [
                    'Login' => $this->pickPointConf->getLogin(),
                    'Password' => $this->pickPointConf->getPassword(),
                ],
            ]);
            $response = json_decode($request->getBody()->getContents(), true);
        } catch (\Exception $exception) {
            throw new PickPointMethodCallException($loginUrl, $exception->getMessage());
        }

        return $response['SessionId'];
    }


    /**
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getPoints()
    {
        $url = $this->pickPointConf->getHost() . '/clientpostamatlist';

        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                'IKN' => $this->pickPointConf->getIKN(),
            ],
        ]);
        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param PackageSize $packageSize
     * @param ReceiverDestination $receiverDestination
     * @param SenderDestination|null $senderDestination
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function calculatePrices(ReceiverDestination $receiverDestination, SenderDestination $senderDestination = null, PackageSize $packageSize = null): array
    {
        $url = $this->pickPointConf->getHost() . '/calctariff';
        /**
         * SenderDestination $senderDestination
         */
        $senderDestination = $senderDestination ?? $this->senderDestination;
        /**
         * PackageSize $packageSize
         */
        $packageSize = $packageSize ?? $this->defaultPackageSize;

        $requestArray = [
            'SessionId' => $this->auth(),
            "IKN" => $this->pickPointConf->getIKN(),
            "FromCity" => $senderDestination != null ? $senderDestination->getCity() : '',
            "FromRegion" => $senderDestination != null ? $senderDestination->getRegion() : '',
            "ToCity" => $receiverDestination->getCity(),
            "ToRegion" => $receiverDestination->getRegion(),
            "PtNumber" => $receiverDestination->getPostamatNumber(),
            "Length" => $packageSize != null ? $packageSize->getLength() : '',
            "Depth" => $packageSize != null ? $packageSize->getDepth() : '',
            "Width" => $packageSize != null ? $packageSize->getWidth() : '',
            "Weight" => $packageSize != null ? $packageSize->getWeight() : ''
        ];

        $request = $this->client->post($url, [
            'json' => $requestArray,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param ReceiverDestination $receiverDestination
     * @param string $tariffType
     * @param SenderDestination|null $senderDestination
     * @param PackageSize|null $packageSize
     * @return TariffPrice
     * @throws PickPointMethodCallException
     */
    public function calculateObjectedPrices(ReceiverDestination $receiverDestination, string $tariffType = 'Standard', SenderDestination $senderDestination = null, PackageSize $packageSize = null): TariffPrice
    {
        $response = $this->calculatePrices($receiverDestination, $senderDestination, $packageSize);

        $tariffPrice = new TariffPrice(
            $response['Services'] ?? [],
            $response['DPMaxPriority'] ?? 0,
            $response['DPMinPriority'] ?? 0,
            $response['DPMax'] ?? 0,
            $response['DPMin'] ?? 0,
            $response['Zone'] ?? 0,
            $response['ErrorMessage'] ?? '',
            $response['ErrorCode'] ?? 0,
            $tariffType
        );

        return $tariffPrice;
    }


    /**
     * Returns invoice data and create shipment/order in delivery service
     * @param Invoice $invoice
     * @param bool $returnInvoiceNumberOnly
     * @return mixed
     * @throws PickPointMethodCallException
     * @throws ValidateException
     */
    public function createShipment(Invoice $invoice): array
    {
        $url = $this->pickPointConf->getHost() . '/CreateShipment';
        InvoiceValidator::validateInvoice($invoice);

        $arrayRequest = [
            "SessionId" => $this->auth(),
            "Sendings" => [
                [
                    "EDTN" => $invoice->getEdtn(),
                    "IKN" => $this->pickPointConf->getIKN(),
                    "Invoice" => [
                        "SenderCode" => $invoice->getSenderCode(), // required
                        "Description" => $invoice->getDescription(), // required
                        "RecipientName" => $invoice->getRecipientName(), // required
                        "PostamatNumber" => $invoice->getPostamatNumber(), // required
                        "MobilePhone" => $invoice->getMobilePhone(), // required
                        "Email" => $invoice->getEmail(),
                        "ConsultantNumber" => $invoice->getConsultantNumber(),
                        "PostageType" => $invoice->getPostageType(), // required
                        "GettingType" => $invoice->getGettingType(), // required
                        "PayType" => Invoice::PAY_TYPE,
                        "Sum" => $invoice->getSum(), // required
                        "PrepaymentSum" => $invoice->getPrepaymentSum(),
                        "InsuareValue" => $invoice->getInsuareValue(),
                        "DeliveryVat" => $invoice->getDeliveryVat(),
                        "DeliveryFee" => $invoice->getDeliveryFee(),
                        "DeliveryMode" => $invoice->getDeliveryMode(), // required
                        "SenderCity" => [
                            "CityName" => $this->senderDestination->getCity(),
                            "RegionName" => $this->senderDestination->getRegion()
                        ],
                        "ClientReturnAddress" => $invoice->getClientReturnAddress(),
                        "UnclaimedReturnAddress" => $invoice->getUnclaimedReturnAddress(),
                        "Places" => [
                            [
                                "Width" => $invoice->getPackageSize()->getWidth(),
                                "Height" => $invoice->getPackageSize()->getLength(),
                                "Depth" => $invoice->getPackageSize()->getDepth(),
                                "Weight" => $invoice->getPackageSize()->getWeight(),
                                "GSBarCode" => $invoice->getGcBarCode(),
                                "CellStorageType" => 0,
                                "SuBEncloses" => [
                                    $invoice->getProducts() // required
                                ]
                            ]
                        ],
                    ]
                ],
            ]
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkCreateShipmentException($response, $url);

        return $response;
    }

    private function checkCreateShipmentException(array $response, string $url)
    {
        $this->checkMethodException($response, $url);

        if (!empty($response['RejectedSendings'][0])) {
            throw new PickPointMethodCallException($url, $response['RejectedSendings'][0]['ErrorMessage'], $response['RejectedSendings'][0]['ErrorCode']);
        }
    }

    /**
     * @param Invoice $invoice
     * @return mixed|void
     * @throws PickPointMethodCallException
     * @throws ValidateException
     */
    public function createShipmentWithInvoice(Invoice $invoice): Invoice
    {
        $response = $this->createShipment($invoice);

        if (!empty($response['CreatedSendings'])) {
            $invoice->setInvoiceNumber($response['CreatedSendings'][0]['InvoiceNumber']);
            $invoice->setBarCode($response['CreatedSendings'][0]['Barcode']);
        }
        return $invoice;
    }

    /**
     * Returns current delivery status
     * @param string $invoiceNumber
     * @param string $orderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getState(string $invoiceNumber, string $orderNumber = ''): State
    {

        $url = $this->pickPointConf->getHost() . '/tracksending';
        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                "InvoiceNumber" => $invoiceNumber,
                "SenderInvoiceNumber" => $orderNumber
            ],
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $response = isset($response) && is_array($response) ? array_pop($response) : [];

        return new State($response['State'] ?? 0, $response['StateMessage'] ?? '');
    }

    /**
     * @param string $invoiceNumber
     * @param string $senderCode
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function cancelInvoice(string $invoiceNumber = '', string $senderCode = ''): array
    {
        $url = $this->pickPointConf->getHost() . '/cancelInvoice';

        $requestArray = [
            'SessionId' => $this->auth(),
            "IKN" => $this->pickPointConf->getIKN(),
        ];
        if (!empty($invoiceNumber)) {
            $requestArray['InvoiceNumber'] = $invoiceNumber;
        }

        if (!empty($senderCode)) {
            $requestArray["GCInvoiceNumber"] = $senderCode;
        }

        $request = $this->client->post($url, [
            'json' => $requestArray
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * Marks on packages
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function printLabel(array $invoiceNumbers): string
    {
        $invoices = !empty($invoiceNumbers) ? $invoiceNumbers : [];

        $url = $this->pickPointConf->getHost() . '/makelabel';
        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                "Invoices" => $invoices,
            ],
        ]);
        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * Marks on packages (zebra printer)
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function printZLabel(array $invoiceNumbers): string
    {
        $invoices = !empty($invoiceNumbers) ? $invoiceNumbers : [];

        $url = $this->pickPointConf->getHost() . '/makezlabel';
        $request = $this->client->post($url, [
            'json' => [
                'SessionId' => $this->auth(),
                "Invoices" => $invoices,
            ],
        ]);
        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }


    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function makeReceipt(array $invoiceNumbers): array
    {

        $url = $this->pickPointConf->getHost() . '/makereestrnumber';
        $array = [
            'SessionId' => $this->auth(),
            "CityName" => $this->senderDestination->getCity(),
            "RegionName" => $this->senderDestination->getRegion(),
            "DeliveryPoint" => $this->senderDestination->getPostamatNumber(),
            "Invoices" => $invoiceNumbers,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        if (!empty($response['ErrorMessage'])) {
            throw new PickPointMethodCallException($url, $response['ErrorMessage']);
        }
        return $response['Numbers'] ?? [];

    }

    /**
     * Returns byte code pdf
     * @param string $identifier
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function printReceipt(string $identifier): string
    {
        $url = $this->pickPointConf->getHost() . '/getreestr';
        $array = [
            'SessionId' => $this->auth(),
            "ReestrNumber" => $identifier
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);
        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function makeReceiptAndPrint(array $invoiceNumbers): string
    {
        $url = $this->pickPointConf->getHost() . '/makereestr';
        $array = [
            'SessionId' => $this->auth(),
            "CityName" => $this->senderDestination->getCity(),
            "RegionName" => $this->senderDestination->getRegion(),
            "DeliveryPoint" => $this->senderDestination->getPostamatNumber(),
            "Invoices" => $invoiceNumbers,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = $request->getBody()->getContents();

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param string $invoiceNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function removeInvoiceFromReceipt(string $invoiceNumber)
    {
        $url = $this->pickPointConf->getHost() . '/removeinvoicefromreestr';
        $array = [
            'SessionId' => $this->auth(),
            'IKN' => $this->pickPointConf->getIKN(),
            "InvoiceNumber" => $invoiceNumber,
        ];
        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getStates(): array
    {
        $url = $this->pickPointConf->getHost() . '/getstates';

        $request = $this->client->get($url);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $states = [];
        foreach ($response as $state) {
            $states[] = new State($state['State'] ?? 0, $state['StateText'] ?? '');
        }

        return $states;
    }

    /**
     * Return all invoices
     * @param $dateFrom
     * @param $dateTo
     * @param string $status
     * @param string $postageType
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getInvoicesByDateRange($dateFrom, $dateTo, $status = null, $postageType = null)
    {
        $dateFrom = (new DateTime($dateFrom))->format('d.m.y H:m');
        $dateTo = (new DateTime($dateTo))->format('d.m.y H:m');
        $url = $this->pickPointConf->getHost() . '/getInvoicesChangeState';

        $array = [
            'SessionId' => $this->auth(),
            'DateFrom' => $dateFrom,
            'DateTo' => $dateTo,
            "State" => $status,

        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param CourierCall $courierCall
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function callCourier(CourierCall $courierCall): CourierCall
    {
        $url = $this->pickPointConf->getHost() . '/courier';
        $array = [
            'SessionId' => $this->auth(),
            'IKN' => $this->pickPointConf->getIKN(),
            'City' => $courierCall->getCityName(),
            "City_id" => $courierCall->getCityId(),
            "Address" => $courierCall->getAddress(),
            "FIO" => $courierCall->getFio(),
            "Phone" => $courierCall->getPhone(),
            "Date" => $courierCall->getDate(),
            "TimeStart" => $courierCall->getTimeStart(),
            "TimeEnd" => $courierCall->getTimeEnd(),
            "Number" => $courierCall->getNumberOfInvoices(),
            "Weight" => $courierCall->getWeight(),
            "Comment" => $courierCall->getComment()
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkCourierCallException($response, $url, $courierCall);

        return $courierCall;
    }

    /**
     * @param string $callOrderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function cancelCourierCall(string $callOrderNumber): array
    {
        $url = $this->pickPointConf->getHost() . '/couriercancel';
        $array = [
            'SessionId' => $this->auth(),
            'OrderNumber' => $callOrderNumber
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param array $response
     * @param string $url
     * @param CourierCall $courierCall
     * @return CourierCall
     * @throws PickPointMethodCallException
     */
    private function checkCourierCallException(array $response, string $url, CourierCall $courierCall)
    {
        $this->checkMethodException($response, $url);

        if (!empty($response['CourierRequestRegistred'])) {
            $courierCall->setCallOrderNumber($response['OrderNumber']);
            return $courierCall;
        }
        throw new PickPointMethodCallException($url, $response['ErrorMessage']);
    }

    /**
     * @param $response
     * @param $urlCall
     * @return mixed
     * @throws PickPointMethodCallException
     */
    private function checkMethodException($response, string $urlCall)
    {
        if (!empty($response['ErrorCode'])) {
            $errorCode = $response['ErrorCode'];
            $errorMessage = $response['Error'] ?? "";
            throw new PickPointMethodCallException($urlCall, $errorMessage, $errorCode);
        }
    }


    /**
     * @param string $invoiceNumber
     * @param string $shopOrderNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function shipmentInfo(string $invoiceNumber, string $shopOrderNumber = ''): array
    {
        $url = $this->pickPointConf->getHost() . '/sendinginfo';
        $array = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoiceNumber,
            "SenderInvoiceNumber" => $shopOrderNumber
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return current($response);
    }

    /**
     * @param string $invoiceNumber
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function findReestrNumberByInvoice(string $invoiceNumber)
    {
        $url = $this->pickPointConf->getHost() . '/getreestrnumber';
        $array = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoiceNumber,
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response['Number'];
    }

    /**
     * @param array $invoiceNumbers
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getInvoicesTrackHistory(array $invoiceNumbers): array
    {
        $url = $this->pickPointConf->getHost() . '/tracksendings';

        $array = [
            'SessionId' => $this->auth(),
            "Invoices" => $invoiceNumbers,
        ];

        $request = $this->client->post($url, [
            'json' => $array,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }

    /**
     * @param string $invoiceNumber
     * @return array
     * @throws \Exception
     */
    public function getInvoiceStatesTrackHistory(string $invoiceNumber): array
    {

        $invoiceHistory = $this->getInvoicesTrackHistory([$invoiceNumber]);
        $states = $invoiceHistory['Invoices'][0]['States'] ?? [];

        $statesResult = [];
        foreach ($states as $state) {
            $valueObjState = new State(
                $state['State'],
                $state['StateMessage'],
                new \DateTime($state['ChangeDT'])
            );
            if (empty($statesResult[$state['State']])) {
                $statesResult[$state['State']] = $valueObjState;
            }
        }

        return $this->sortArrayOfStatesByDateAsc($statesResult);
    }

    /**
     * @param array $states
     * @return array
     */
    private function sortArrayOfStatesByDateAsc(array $states): array
    {
        usort($states, function (State $a, State $b) {
            if ($a->getChangeDate() === $b->getChangeDate()) {
                return 0;
            }

            return $a->getChangeDate() < $b->getChangeDate() ? -1 : 1;
        });

        return $states;
    }

    /**
     *
     * @param array $invoiceNumbers
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getArrayInvoicesWithTrackHistory(array $invoiceNumbers): array
    {
        $invoiceHistory = $this->getInvoicesTrackHistory($invoiceNumbers);
        $invoiceNumbersWithHistory = [];

        foreach ($invoiceHistory['Invoices'] as $invoice) {
            $states = $invoice['States'] ?? [];
            $statesResult = [];
            foreach ($states as $state) {
                $valueObjState = new State(
                    $state['State'],
                    $state['StateMessage'],
                    new \DateTime($state['ChangeDT'])
                );
                if (empty($statesResult[$state['State']])) {
                    $statesResult[$state['State']] = $valueObjState;
                }
            }
            if (in_array($invoice['InvoiceNumber'], $invoiceNumbers)) {
                $invoiceNumbersWithHistory[$invoice['InvoiceNumber']] = $this->sortArrayOfStatesByDateAsc($statesResult);
            }
        }

        return $invoiceNumbersWithHistory;
    }

    /**
     * @param array $invoiceNumbers
     * @return array
     * @throws PickPointMethodCallException
     */
    public function getInvoicesLastStates(array $invoiceNumbers): array
    {
        $invoiceNumbersWithHistory = $this->getArrayInvoicesWithTrackHistory($invoiceNumbers);
        $invoicesWithFinalStates = [];
        foreach ($invoiceNumbersWithHistory as $invoiceNumber => $history) {
            $finalState = end($history);
            $invoicesWithFinalStates[$invoiceNumber] = $finalState;
        }

        return $invoicesWithFinalStates;
    }

    /**
     * @param Invoice $invoice
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function updateShipment(Invoice $invoice): array
    {
        $url = $this->pickPointConf->getHost() . '/updateInvoice';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'InvoiceNumber' => $invoice->getInvoiceNumber(),
            'BarCode' => $invoice->getBarCode()
        ];
        if (!empty($invoice->getPostamatNumber())) {
            $arrayRequest['PostamatNumber'] = $invoice->getPostamatNumber();
        }
        if (!empty($invoice->getRecipientName())) {
            $arrayRequest['RecipientName'] = $invoice->getRecipientName();
        }
        if (!empty($invoice->getMobilePhone())) {
            $arrayRequest['Phone'] = $invoice->getMobilePhone();
        }
        if (!empty($invoice->getEmail())) {
            $arrayRequest['Email'] = $invoice->getEmail();
        }

        $arrayRequest['PostageType'] = $invoice->getSum() == 0
            ? Invoice::POSTAGE_TYPE_STANDARD
            : Invoice::POSTAGE_TYPE_STANDARD_NP;

        $arrayRequest['Sum'] = $invoice->getSum();

        if (!empty($invoice->getProducts())) {
            $arrayRequest['SubEncloses'] = $invoice->getProducts();
        }

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response;
    }


    /**
     * @param string $barCode
     * @return PackageSize
     * @throws PickPointMethodCallException
     */
    public function getPackageInfo(string $barCode): PackageSize
    {
        $url = $this->pickPointConf->getHost() . '/encloseinfo';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'BarCode' => $barCode
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        $enclose = $response['Enclose'];

        $packageSize = new PackageSize($enclose['Width'], $enclose['Height'], $enclose['Depth'], $enclose['Weight']);

        return $packageSize;
    }

    /**
     * @param int $postIndex
     * @return mixed
     * @throws PickPointMethodCallException
     */
    public function getClosestPostamatList(int $postIndex)
    {
        $url = $this->pickPointConf->getHost() . '/postindexpostamatlist';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'PostIndex' => $postIndex
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response['PostamatList'] ?? null;
    }

    /**
     * Команда предназначена для получения акта возврата денег.
     * https://pickpoint.ru/sales/api/#_Toc24018654
     * @param null $actNumber
     * @param null $dateFrom
     * @param null $dateEnd
     * @return mixed|null
     * @throws PickPointMethodCallException
     */
    public function getMoneyReturnOrder($actNumber = null, $dateFrom = null, $dateEnd = null)
    {
        $url = $this->pickPointConf->getHost() . '/getmoneyreturnorder';

        $arrayRequest = [
            'SessionId' => $this->auth(),
            'IKN' => $this->pickPointConf->getIKN(),
            'DocumentNumber' => $actNumber,
            'DateFrom' => $dateFrom,
            'DateEnd' => $dateEnd
        ];

        $request = $this->client->post($url, [
            'json' => $arrayRequest,
        ]);

        $response = json_decode($request->getBody()->getContents(), true);

        $this->checkMethodException($response, $url);

        return $response ?? null;
    }
}