import 'dart:async';

import 'inventory_repository.dart';
import '../models/inventory_models.dart';

class MockInventoryRepository implements InventoryRepository {
  final _storages = const [
    StorageLocation(id: 1, name: 'KONA Main', isDefault: true),
    StorageLocation(id: 2, name: 'KONA Office'),
    StorageLocation(id: 3, name: 'Event Store'),
  ];

  late final List<InventoryItem> _items = [
    const InventoryItem(
      id: 15,
      name: 'Blue Wristband',
      sku: 'WB-BLUE',
      barcode: '62811001',
      unit: 'pcs',
      quantity: 246,
      storageId: 1,
      storageName: 'KONA Main',
      packagePresets: [
        ItemPackagePreset(
          id: 101,
          label: 'Bag',
          piecesPerUnit: 10,
          isDefault: true,
        ),
        ItemPackagePreset(id: 102, label: 'Box', piecesPerUnit: 50),
      ],
    ),
    const InventoryItem(
      id: 16,
      name: 'Dark Red Wristband',
      sku: 'WB-DARK-RED',
      barcode: '62811002',
      unit: 'pcs',
      quantity: 310,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 17,
      name: 'Green Wristband',
      sku: 'WB-GREEN',
      barcode: '62811003',
      unit: 'pcs',
      quantity: 314,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 18,
      name: 'Yellow-Blue Wristband',
      sku: 'WB-YELLOW-BLUE',
      barcode: '62811004',
      unit: 'pcs',
      quantity: 223,
      storageId: 1,
      storageName: 'KONA Main',
    ),
    const InventoryItem(
      id: 19,
      name: 'Cleaning Gloves',
      sku: 'CL-GLOVE-M',
      barcode: '62812001',
      unit: 'pairs',
      quantity: 48,
      storageId: 2,
      storageName: 'KONA Office',
    ),
    const InventoryItem(
      id: 20,
      name: 'Guest Towels',
      sku: 'GT-TOWEL-W',
      barcode: '62812002',
      unit: 'pcs',
      quantity: 72,
      storageId: 2,
      storageName: 'KONA Office',
    ),
  ];

  final List<MobileTask> _tasks = [
    const MobileTask(
      id: 101,
      reference: 'HDO-20260810-7A21',
      title: 'Confirm wristband receipt',
      status: 'awaiting_receipt',
      purpose: 'temporary_use',
      itemCount: 3,
      quantity: 326,
      source: 'KONA Main',
      allowedActions: {'confirm_receipt'},
      requiresAction: true,
    ),
    const MobileTask(
      id: 102,
      reference: 'HDO-20260810-9F42',
      title: 'Office transfer arriving',
      status: 'awaiting_receipt',
      purpose: 'storage_transfer',
      itemCount: 2,
      quantity: 80,
      source: 'KONA Main',
      destination: 'KONA Office',
      allowedActions: {'confirm_receipt'},
      requiresAction: true,
    ),
    const MobileTask(
      id: 103,
      reference: 'HDO-20260807-1C55',
      title: 'Cleaning crew custody',
      status: 'delivered',
      purpose: 'staff_custody',
      itemCount: 2,
      quantity: 8,
      source: 'KONA Office',
      allowedActions: {'return_custody'},
      requiresAction: true,
    ),
  ];

  Future<void> _wait() =>
      Future<void>.delayed(const Duration(milliseconds: 350));

  @override
  Future<void> login(String email, String password) async => _wait();

  @override
  Future<void> logout() async => _wait();

  @override
  Future<MobileBootstrap> bootstrap() async {
    await _wait();
    return MobileBootstrap(
      userName: 'Alaa',
      storages: _storages,
      items: List.unmodifiable(_items),
      tasks: List.unmodifiable(_tasks),
      capabilities: const {
        'usage',
        'restock',
        'transfer',
        'handover',
        'custody',
      },
      permissions: const {
        'items.view',
        'movements.usage',
        'movements.restock',
        'handovers.view',
        'handovers.create',
        'handovers.request',
        'handovers.close',
        'handovers.approve',
        'handovers.custody_return',
      },
      recipients: const [
        MobileRecipient(id: 7, name: 'Alaa', position: 'Reception Staff'),
        MobileRecipient(id: 8, name: 'Khalid', position: 'Operations Staff'),
        MobileRecipient(id: 9, name: 'Mona', position: 'Cleaning Crew'),
      ],
      settings: const {
        'manual_restock_enabled': true,
        'offline_drafts_enabled': true,
        'require_usage_proof': false,
        'min_supported_version': '1.0.0',
        'usage_reasons': [
          {
            'code': 'online',
            'label': 'Online',
            'active': true,
            'sort_order': 1,
            'requires_custom_text': false,
          },
          {
            'code': 'walkin',
            'label': 'Walk-in',
            'active': true,
            'sort_order': 2,
            'requires_custom_text': false,
          },
          {
            'code': 'event',
            'label': 'Event',
            'active': true,
            'sort_order': 3,
            'requires_custom_text': false,
          },
          {
            'code': 'damage',
            'label': 'Damage',
            'active': true,
            'sort_order': 4,
            'requires_custom_text': false,
          },
          {
            'code': 'sport',
            'label': 'Sport',
            'active': true,
            'sort_order': 5,
            'requires_custom_text': false,
          },
          {
            'code': 'school',
            'label': 'School',
            'active': true,
            'sort_order': 6,
            'requires_custom_text': false,
          },
          {
            'code': 'complimentary',
            'label': 'Complimentary',
            'active': true,
            'sort_order': 7,
            'requires_custom_text': false,
          },
          {
            'code': 'no_show',
            'label': 'No Show',
            'active': true,
            'sort_order': 8,
            'requires_custom_text': false,
          },
          {
            'code': 'other',
            'label': 'Other',
            'active': true,
            'sort_order': 9,
            'requires_custom_text': true,
          },
        ],
      },
    );
  }

  @override
  Future<List<InventoryItem>> searchItems(
    String query, {
    int? storageId,
  }) async {
    await _wait();
    final normalized = query.trim().toLowerCase();
    return _items.where((item) {
      final inStorage = storageId == null || item.storageId == storageId;
      final matches =
          normalized.isEmpty ||
          '${item.name} ${item.sku} ${item.barcode ?? ''}'
              .toLowerCase()
              .contains(normalized);
      return inStorage && matches;
    }).toList();
  }

  @override
  Future<List<MobileOperation>> operations() async {
    await _wait();
    final now = DateTime.now();
    return [
      MobileOperation(
        id: 3,
        clientOperationId: 'mock-usage-3',
        type: 'movement_batch',
        status: 'succeeded',
        reference: 'MOV-MOCK-103',
        message: 'Usage accepted by the server.',
        createdAt: now.subtract(const Duration(minutes: 12)),
        completedAt: now.subtract(const Duration(minutes: 12)),
      ),
      MobileOperation(
        id: 2,
        clientOperationId: 'mock-handover-2',
        type: 'handover_create',
        status: 'succeeded',
        reference: 'HDO-MOCK-102',
        message: 'Handover created.',
        createdAt: now.subtract(const Duration(hours: 2)),
        completedAt: now.subtract(const Duration(hours: 2)),
      ),
      MobileOperation(
        id: 1,
        clientOperationId: 'mock-conflict-1',
        type: 'movement_batch',
        status: 'failed',
        message: 'Balance changed. Review the latest quantity.',
        createdAt: now.subtract(const Duration(days: 1)),
        completedAt: now.subtract(const Duration(days: 1)),
      ),
    ];
  }

  @override
  Future<List<MobileTask>> handovers() async {
    await _wait();
    return List.unmodifiable(_tasks);
  }

  @override
  Future<HandoverDetail> handover(int id) async {
    await _wait();
    final task = _tasks.firstWhere((entry) => entry.id == id);
    final matching = _items.take(task.itemCount).toList();
    return HandoverDetail(
      task: task,
      recipientName: 'Alaa',
      issuerName: 'Ahmad Dalao',
      scheduledForDate: '2026-08-10',
      reviewDate: task.purpose == 'staff_custody' ? '2026-10-10' : null,
      lines: [
        for (var index = 0; index < matching.length; index++)
          HandoverLine(
            id: id * 10 + index,
            itemId: matching[index].id,
            name: matching[index].name,
            sku: matching[index].sku,
            barcode: matching[index].barcode,
            unit: matching[index].unit,
            imageUrl: matching[index].imageUrl,
            quantityIssued: task.quantity / matching.length,
            quantityReceived: task.status == 'awaiting_receipt'
                ? 0
                : task.quantity / matching.length,
            quantityUsed: 0,
            quantityReturned: 0,
            quantityHeld: task.quantity / matching.length,
          ),
      ],
    );
  }

  @override
  Future<OperationReceipt> submitUsage({
    required int storageId,
    required List<CartLine> lines,
    required String defaultReason,
    String? defaultCustomReason,
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'MOV-MOCK-${DateTime.now().millisecondsSinceEpoch}',
      status: 'completed',
      message: '${lines.length} lines queued safely.',
    );
  }

  @override
  Future<OperationReceipt> submitRestock({
    required int storageId,
    required List<CartLine> lines,
    String? reference,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference:
          reference ?? 'RST-MOCK-${DateTime.now().millisecondsSinceEpoch}',
      status: 'completed',
    );
  }

  @override
  Future<OperationReceipt> createHandover({
    required String purpose,
    required int sourceStorageId,
    int? destinationStorageId,
    int? recipientUserId,
    required List<CartLine> lines,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-${DateTime.now().millisecondsSinceEpoch}',
      status: 'requested',
    );
  }

  @override
  Future<OperationReceipt> confirmReceipt(
    int handoverId,
    Map<int, double> quantities, {
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: 'delivered',
      message: 'Receipt quantities submitted.',
    );
  }

  @override
  Future<OperationReceipt> confirmReceiptReview(
    int handoverId,
    Map<int, double> quantities, {
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: 'delivered',
      message: 'Receipt difference approved.',
    );
  }

  @override
  Future<OperationReceipt> submitCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    String? notes,
    String? proofPath,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: 'pending_approval',
      message: 'Closeout submitted for issuer approval.',
    );
  }

  @override
  Future<OperationReceipt> approveCloseout({
    required int handoverId,
    required Map<int, double> returnedQuantities,
    required Map<String, Map<String, double>> reconciliations,
    Map<String, String> discrepancyNotes = const {},
    Map<String, String> varianceReasons = const {},
    Map<String, String> varianceNotes = const {},
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: 'closed',
      message: 'Final stock approved.',
    );
  }

  @override
  Future<OperationReceipt> decideRequest(
    int handoverId, {
    required bool approve,
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: approve ? 'awaiting_receipt' : 'rejected',
    );
  }

  @override
  Future<OperationReceipt> cancelHandover(
    int handoverId, {
    String? notes,
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'HDO-MOCK-$handoverId',
      status: 'cancelled',
    );
  }

  @override
  Future<OperationReceipt> submitCustodyReturn({
    required int handoverId,
    required List<CustodyReturnLine> lines,
    String? notes,
    Map<int, String> damageProofPaths = const {},
    String? clientOperationId,
  }) async {
    await _wait();
    return OperationReceipt(
      reference: 'CTR-MOCK-$handoverId',
      status: 'pending_approval',
      message: 'Custody return submitted.',
    );
  }
}
