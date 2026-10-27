@once
    @push('styles')
        <style>
            .qorder{min-width:0;padding:20px 18px;border:1px solid #e0e6ee;border-radius:18px;background:#fff;color:var(--ink);scroll-margin-top:180px;font-family:var(--sans)}
            .qorder [x-cloak]{display:none!important}
            .qorder__heading{margin:0 0 18px;font:600 17px/1.45 var(--caps);color:#202c39}
            .actionbar[data-quick-order-bar][hidden]{display:none!important}
            .qorder__unavailable{display:flex;align-items:center;gap:7px;margin:0 0 16px;padding:10px 11px;background:#f1f3f5;border-radius:9px;color:#788492;font-size:12px;line-height:1.5}
            .qorder__form{margin:0;padding:0}
            .qorder__contacts{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px;margin-bottom:22px}
            .qorder__field{min-width:0}
            .qorder__field>label{display:block;margin-bottom:7px;font-size:11px;line-height:1.5;color:#718093}
            .qorder__field input{display:block;width:100%;min-width:0;height:44px;margin:0;padding:10px 11px;border:1px solid #e1e7ee;background:#fafbfd;border-radius:9px;color:#263343;font:400 16px/1.4 var(--sans)}
            .qorder__field input::placeholder{color:#9ca8b7;font-size:12px}
            .qorder__field.is-bad input{border-color:#d96b65}
            .qorder__error{display:block;color:#bf504b;font-size:11px;line-height:1.5;margin:6px 0 0}
            .qorder__error:empty{display:none}
            .qorder__payments{margin:0;padding:0;border:0;min-width:0}
            .qorder__payments>legend{display:flex;align-items:center;gap:8px;width:100%;padding:0;margin:0 0 13px;font-size:17px;line-height:1.45;font-weight:600;font-family:var(--caps);color:#202c39}
            .qorder__payments>legend>.ic{color:var(--red-bright)}
            .qorder__payment-list{display:grid;gap:9px}
            .qorder__family{min-width:0}
            .qorder__choice{position:relative;display:flex;align-items:center;gap:10px;min-height:47px;padding:10px 11px;border:1px solid #dfe6ef;border-radius:12px;background:#fff;cursor:pointer;transition:background-color .12s,border-color .12s}
            .qorder__choice:has(>input:checked){border-color:#ff8734;background:#fff6ed}
            .qorder__choice:has(>input:focus-visible){outline:2px solid #ffad72;outline-offset:3px}
            .qorder__choice.is-unavailable{opacity:.48;cursor:default}
            .qorder__choice>input{position:absolute;opacity:0;width:1px;height:1px;margin:0}
            .qorder__icon{display:flex;align-items:center;justify-content:center;width:18px;flex-shrink:0;color:#8a98aa}
            .qorder__choice-label{flex:1;min-width:0;font-size:13px;line-height:1.5;color:#697b90}
            .qorder__choice:has(>input:checked) .qorder__icon,.qorder__choice:has(>input:checked) .qorder__choice-label{color:#dc6512}
            .qorder__networks{display:flex;align-items:center;gap:5px;margin-left:auto;flex-shrink:0}
            .qorder__networks img{display:block;max-width:39px;height:21px;object-fit:contain}
            .qorder__meta{display:flex;align-items:center;gap:6px;font-size:11px;color:#95a1af;white-space:nowrap}
            .qorder__monthly{display:inline-flex;align-items:center;padding:4px 7px;border-radius:5px;background:#e4f7ec;color:#16914e;font-size:11px;line-height:1.4;font-weight:500}
            .qorder__chevron{transition:transform .12s}
            .qorder__family.is-expanded .qorder__chevron{transform:rotate(180deg)}
            .qorder__check{display:flex;align-items:center;justify-content:center;width:16px;height:16px;border:1px solid #dce3ec;border-radius:50%;background:#fff;flex-shrink:0}
            .qorder__choice:has(>input:checked) .qorder__check{border-color:#ff7b22;background:#ff7b22}
            .qorder__choice:has(>input:checked) .qorder__check:after{content:'';width:5px;height:5px;border-radius:50%;background:#fff}
            .qorder__installments{display:grid;gap:6px;margin:9px 0 2px;padding-left:10px;border-left:1px solid #f0d9c5}
            .qorder__bank{min-height:44px;padding:8px 9px;border-color:#e7ebf1;border-radius:9px;gap:9px}
            .qorder__bank-logo{display:flex;align-items:center;justify-content:flex-start;width:91px;min-width:0;flex-shrink:0}
            .qorder__bank-logo img{display:block;max-width:100%;max-height:29px;object-fit:contain}
            .qorder__bank-logo>span{font-size:11px;line-height:1.4;color:#718093}
            .qorder__bank .qorder__choice-label{font-size:12px}
            .qorder__bank .qorder__check{width:14px;height:14px}
            .qorder__footer{display:flex;gap:10px;margin-top:20px}
            .qorder__cart{display:flex;align-items:center;justify-content:center;width:45px;min-height:47px;border:1px solid #d7e0eb;border-radius:11px;background:#fff;color:#6d7f94;flex-shrink:0}
            .qorder__submit{display:flex;align-items:center;justify-content:center;gap:8px;min-height:47px;min-width:0;flex:1;padding:11px 12px;border:0;border-radius:11px;background:#18ae60;color:#fff;font:500 14px/1.5 var(--caps)}
            .qorder__submit:hover{background:#159b55}
            .qorder__resume{display:flex;align-items:center;justify-content:center;gap:8px;min-height:44px;margin-top:14px;padding:11px 12px;border-radius:10px;background:#18ae60;color:#fff;text-align:center;font-size:13px;line-height:1.5}
            .qorder__resume:hover{background:#159b55;color:#fff}
            .qorder__submit:disabled{background:#b1b8c1;color:#fff;opacity:1;cursor:default}
            .qorder__cart:disabled{background:#f0f2f5;color:#9aa3ae;opacity:1;cursor:default}
            .qorder__submit.is-loading,.qorder__cart.is-loading{opacity:.65;cursor:wait}
            .btn-buy.is-unavailable{background:#b1b8c1;box-shadow:none;cursor:default}
            @media(min-width:1025px) and (max-width:1280px){.qorder{padding:18px 14px}.qorder__contacts{grid-template-columns:1fr}.qorder__bank-logo{width:79px}.qorder__bank .qorder__choice-label{font-size:11px}}
            @media(max-width:380px){.qorder{padding:18px 14px}.qorder__contacts{gap:8px}.qorder__choice{padding:9px;gap:8px}.qorder__choice-label{font-size:12px}.qorder__bank-logo{width:79px}.qorder__bank .qorder__choice-label{font-size:11px}.qorder__payments>legend{font-size:16px}.qorder__monthly{padding:4px 6px}.qorder__meta{gap:3px}.qorder__networks{gap:3px}.qorder__networks img{max-width:29px}.qorder__submit{font-size:13px}}
            @media(max-width:340px){.qorder__contacts{grid-template-columns:1fr}.qorder__bank-logo{width:72px}}
            @media(prefers-reduced-motion:reduce){.qorder__choice,.qorder__chevron{transition:none}}
        </style>
    @endpush
@endonce
