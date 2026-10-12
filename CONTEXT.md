# Vending Machine

A single vending machine: the stock it holds, the cash reserve it pays change
from, and the funds a customer has inserted into it.

## Language

**Vending machine**:
A single physical unit and the stock, cash reserve and inserted funds it holds.
_Avoid_: kiosk, dispenser, unit

**Product**:
An item a machine can sell, identified by its selector code, with a price and
the number of units currently loaded.
_Avoid_: item, SKU, article

**Selector code**:
The short code a customer enters to choose a product. Within a machine it
identifies exactly one loaded product.
_Avoid_: slot, button, position, shelf

**Stock**:
The products currently loaded in a machine, keyed by selector code.
_Avoid_: inventory, load, contents

**Denomination**:
A single physical coin or note, described by its amount.
_Avoid_: coin type, denomination value, unit

**Cash reserve**:
The physical coins and notes a machine holds in order to pay change.
_Avoid_: change, float, hopper, cash box

**Change due**:
The money a machine owes back to a customer after an overpayment.
_Avoid_: change, return, refund

**Inserted funds**:
Money a customer has put into a machine that has not yet been spent or returned.
_Avoid_: balance, credit, tender, payment

**Pending transaction**:
The state in which a machine holds inserted funds and the purchase has not yet
settled.
_Avoid_: open sale, incomplete order, in-flight cart

**Selection**:
A customer choosing a loaded product to buy with the funds already inserted.
Settles the pending transaction: the product is vended, any change due is paid
from the cash reserve, and the inserted funds are reset.
_Avoid_: purchase, buy, order, sale

**Vended item**:
The product a machine hands over on a successful selection, together with the
change due.
_Avoid_: dispensed item, product out, output

**Return**:
A customer taking back every coin they inserted, ending the pending transaction
without a sale. The coins handed back are exactly those inserted, never drawn
from the cash reserve.
_Avoid_: refund, cancel, eject, coin return

**Service**:
A technician declaring a machine's true stock and cash reserve, replacing
whatever was there. Rejected while a transaction is pending.
_Avoid_: restock, refill, fill, replenish

**Money**:
An amount of currency held in integer minor units (cents).
_Avoid_: price, amount (when a distinct type is meant)
