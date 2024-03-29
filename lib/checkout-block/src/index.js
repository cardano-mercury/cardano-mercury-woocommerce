import { decodeEntities } from '@wordpress/html-entities';
import { useEffect } from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';
import { RadioControl } from '@wordpress/components';
import { useState } from 'react';

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;

const settings = getSetting('cardano_mercury_data', {});

wp.hooks.addAction('experimental__woocommerce_blocks-checkout-render-checkout-form', 'cardano-mercury', (data) => {
	const payment_method = wp.data.select('wc/store/payment').getActivePaymentMethod();
	window.wc.blocksCheckout.extensionCartUpdate({
		namespace: 'cardano-mercury',
		data: {
			payment_method: payment_method,
		},
	});
});

wp.hooks.addAction('experimental__woocommerce_blocks-checkout-set-active-payment-method', 'cardano-mercury', (payment_method) => {
	window.wc.blocksCheckout.extensionCartUpdate({
		namespace: 'cardano-mercury',
		data: {
			payment_method: payment_method?.value,
		},
	});
});

const label = decodeEntities(settings.title || 'Cardano ($ADA)');

const Description = (props) => {
	return (
		<p>{props.value}</p>
	);
};

const Currency = (props) => {
	const [option, setOption] = useState(props.chosen);
	const doUpdate = (value) => {
		setOption(value);
		props.updateCurrency(value);
	};
	return (
		<RadioControl label='Select Native Asset' selected={option} options={props.currencies} onChange={doUpdate} />);
};

const Content = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup, onCheckoutBeforeProcessing } = eventRegistration;

	const orderTotal = props.billing.cartTotal.value * Math.pow(10, props.billing.currency.minorUnit * -1);
	const orderUSD = orderTotal * settings.exchange_rate;
	const orderADA = orderUSD / settings.ada_price;
	const currencyOptions = [];

	settings.currencies.forEach((curr) => {
		let curr_value, ada_balance, label;
		if (curr.unit === 'ada') {
			curr_value = orderUSD / curr.price;
			label = `${curr_value.toFixed(curr.decimals)} ₳`;
		} else {
			ada_balance = Math.max(0, orderADA - curr.minUTxO);
			if (ada_balance <= 0) {
				return;
			}
			curr_value = ada_balance / curr.perAdaPrice;

			if (curr_value <= 0) {
				return;
			}
			label = `${curr_value.toFixed(curr.decimals)} ${curr.name} + ${curr.minUTxO} ₳`;
		}
		currencyOptions.push({
			label: label,
			value: curr.unit,
		});
	});

	const [chosenCurrency, setChosenCurrency] = useState(null);

	useEffect(() => {
		const unsubscribePayment = onPaymentSetup(async () => {
			if (!chosenCurrency) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: 'You must select the Cardano Native Asset you wish to pay with!',
				};
			}

			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						cardano_currency: chosenCurrency,
					},
				},
			};
		});

		return () => {
			unsubscribePayment();
		};
	}, [
		chosenCurrency,
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		onPaymentSetup,
		onCheckoutBeforeProcessing,
	]);


	return (
		<div>
			<Description value={decodeEntities(settings.description || '')} />
			<Currency currencies={currencyOptions} chosen={chosenCurrency}
					  updateCurrency={setChosenCurrency} />
		</div>
	);
};


const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={label} />;
};

const methodData = {
	name: 'cardano_mercury',
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: ['products'],
	},
};

registerPaymentMethod(methodData);
