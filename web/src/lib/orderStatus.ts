import type { OrderStatus } from './api'

export const terminalOrderLabels: Partial<Record<OrderStatus, string>> = {
  rejected_by_restaurant: 'Rejected by restaurant',
  cancelled_by_customer: 'Cancelled by you',
  cancelled_by_restaurant: 'Cancelled by restaurant',
  cancelled_no_rider: 'Cancelled — no rider found',
  cancelled_by_admin: 'Cancelled by Foodjett',
  failed_delivery: 'Delivery unsuccessful',
}

const progressLabels: Partial<Record<OrderStatus, string>> = {
  placed: 'Order placed',
  accepted: 'Accepted',
  preparing: 'Preparing',
  ready: 'Ready for pickup',
  finding_rider: 'Finding a rider',
  rider_assigned: 'Rider assigned',
  at_restaurant: 'At the restaurant',
  picked_up: 'Picked up',
  on_the_way: 'On the way',
  arrived: 'Rider arrived',
  delivered: 'Delivered',
}

export function orderStatusLabel(status: OrderStatus): string {
  return progressLabels[status] ?? terminalOrderLabels[status] ?? status.replaceAll('_', ' ')
}

export function isTerminalOrderStatus(status: OrderStatus): boolean {
  return status === 'delivered' || status in terminalOrderLabels
}
