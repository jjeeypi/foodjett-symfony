import { useState } from 'react'

function Icon({ name, size = 20 }: { name: string; size?: number }) {
  const paths: Record<string, React.ReactNode> = {
    home: <><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/><path d="M9 21v-8h6v8"/></>,
    bag: <><rect x="5" y="7" width="14" height="14" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></>,
    heart: <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>,
    message: <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8A8.5 8.5 0 0 1 12.5 20a8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 8.7 3.9a8.4 8.4 0 0 1 3.8-.9H13a8.5 8.5 0 0 1 8 8v.5Z"/>,
    user: <><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></>,
    search: <><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></>,
    pin: <><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></>,
    arrow: <><path d="M4 12h16m-6-6 6 6-6 6"/></>,
    clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
    star: <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z"/>,
    tune: <><path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="9" cy="18" r="2"/></>,
    help: <><circle cx="12" cy="12" r="9"/><path d="M9.5 8a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 4h.01"/></>,
    bolt: <path d="m13 2-9 12h7l-1 8 10-13h-7Z"/>,
    bike: <><circle cx="5" cy="17" r="4"/><circle cx="19" cy="17" r="4"/><path d="m5 17 5-10 5 10H5m5-10h5l4 10M8 4h4"/></>,
    down: <path d="m7 10 5 5 5-5"/>,
    close: <path d="m6 6 12 12M6 18 18 6"/>,
    leaf: <><path d="M20 3C8 2 2 9 6 16s16 3 14-13Z"/><path d="m5 21 11-12"/></>,
  }
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[name] || paths.bag}</svg>
}

const burger = 'https://images.unsplash.com/photo-1619810816144-223f5b027aea?auto=format&fit=crop&w=900&q=85'
const pizza = 'https://images.unsplash.com/photo-1703575572016-b82f05e6d4f9?auto=format&fit=crop&w=800&q=85'
const platter = 'https://images.unsplash.com/photo-1662186342592-5b1236e95a4e?auto=format&fit=crop&w=800&q=85'
const sandwich = 'https://images.unsplash.com/photo-1686554825530-0d93d133af41?auto=format&fit=crop&w=800&q=85'
const restaurants = [
  { id: 1, name: 'The Good Burger', cuisine: 'Burgers', description: 'American · Burgers · Comfort food', image: burger, rating: '4.9', reviews: '200+', time: '20–30', fee: 'Free', tag: 'A neighborhood favorite', price: 249 },
  { id: 2, name: 'The Pizza Project', cuisine: 'Pizza', description: 'Italian · Wood-fired pizza · Pasta', image: pizza, rating: '4.8', reviews: '150+', time: '25–35', fee: '₱35', tag: '20% off your first order', price: 349 },
  { id: 3, name: 'Tokyo Table', cuisine: 'Asian', description: 'Japanese · Sushi · Rice bowls', image: platter, rating: '4.9', reviews: '100+', time: '20–35', fee: '₱29', tag: 'Top rated', price: 289 },
  { id: 4, name: 'Daily Greens', cuisine: 'Healthy', description: 'Healthy · Fresh bowls · Sandwiches', image: sandwich, rating: '4.7', reviews: '80+', time: '15–25', fee: 'Free', tag: 'Fresh & feel-good', price: 219 },
  { id: 5, name: 'Burger & Co.', cuisine: 'Burgers', description: 'Smash burgers · Fries · Shakes', image: burger, rating: '4.8', reviews: '120+', time: '15–25', fee: '₱25', tag: 'Made for your cravings', price: 199 },
  { id: 6, name: 'Casa Italiana', cuisine: 'Pizza', description: 'Italian · Pizza · Family favorites', image: pizza, rating: '4.6', reviews: '90+', time: '30–40', fee: '₱35', tag: 'Local favorite', price: 399 },
]
const navigation = [['Explore', 'home'], ['My orders', 'bag'], ['Favorites', 'heart'], ['Messages', 'message'], ['My account', 'user']]
const categories = [['All', 'all'], ['Burgers', 'burger'], ['Pizza', 'pizza'], ['Asian', 'noodles'], ['Healthy', 'leaf'], ['Chicken', 'chicken'], ['Desserts', 'dessert'], ['Drinks', 'drink']]

function FoodIcon({ kind }: { kind: string }) {
  return <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    {kind === 'all' ? <><rect x="8" y="8" width="12" height="12" rx="4"/><rect x="28" y="8" width="12" height="12" rx="4"/><rect x="8" y="28" width="12" height="12" rx="4"/><rect x="28" y="28" width="12" height="12" rx="4"/></>
    : kind === 'burger' ? <><path d="M8 22c0-20 32-20 32 0Z" fill="#f4c670"/><path d="M7 27h34M8 32l8 3 8-3 8 3 8-3"/><path d="M9 37h30v3H9Z" fill="#f4c670"/><path d="m18 15 1 1m8-3 1 1"/></>
    : kind === 'pizza' ? <><path d="m10 7 30 7-25 28Z" fill="#f9d880"/><path d="m10 7 30 7-3 5-25-6Z" fill="#c38c53"/><circle cx="20" cy="21" r="3" fill="#d57459"/><circle cx="27" cy="24" r="2" fill="#d57459"/><circle cx="17" cy="32" r="2" fill="#d57459"/></>
    : kind === 'leaf' ? <><path d="M39 9C11 5 5 27 18 35S43 29 39 9Z" fill="#abd39b"/><path d="m10 41 22-22m-9 9v-8m0 8h9"/></>
    : kind === 'drink' ? <><path d="m13 18 3 24h17l3-24Z" fill="#e0b793"/><path d="M11 17h27M25 17l3-11h8M18 26h14"/></>
    : kind === 'dessert' ? <><path d="m15 25 9 19 10-19Z" fill="#e9bc80"/><path d="M10 24c-3-6 2-11 6-11-1-12 18-12 17 0 6 0 9 7 5 11Z" fill="#e9b9c2"/></>
    : kind === 'chicken' ? <><path d="M34 9c11 9 0 24-11 21l-6 6-5-5 6-6c-3-11 7-22 16-16Z" fill="#d99858"/><path d="m17 36-5 5c-5 2-10-4-5-8l5-2"/></>
    : <><path d="M7 25h34c-2 15-31 15-34 0Z" fill="#d3e3cc"/><path d="M14 24V13m6 11V10m6 14V13M11 9l28-5M12 14l28-5"/></>}
  </svg>
}

export default function App() {
  const [page, setPage] = useState('Explore')
  const [category, setCategory] = useState('All')
  const [query, setQuery] = useState('')
  const [favorites, setFavorites] = useState<number[]>([])
  const [selected, setSelected] = useState<typeof restaurants[number] | null>(null)
  const [cart, setCart] = useState<{ name: string; price: number }[]>([])
  const [modal, setModal] = useState('')
  const [address, setAddress] = useState('123 Mabini Street, Makati')
  const [freeDelivery, setFreeDelivery] = useState(false)
  const [sort, setSort] = useState('Recommended')
  const [ordered, setOrdered] = useState(false)
  const [notice, setNotice] = useState('')
  const filtered = restaurants.filter(r => (category === 'All' || r.cuisine === category) && (r.name + r.description).toLowerCase().includes(query.toLowerCase()) && (!freeDelivery || r.fee === 'Free') && (page !== 'Favorites' || favorites.includes(r.id))).sort((a, b) => sort === 'Top rated' ? Number(b.rating) - Number(a.rating) : sort === 'Fastest delivery' ? parseInt(a.time) - parseInt(b.time) : a.id - b.id)
  function save(id: number) { setFavorites(prev => prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]) }
  function close() { setModal(''); setSelected(null) }
  return <div className="app">
    <aside className="sidebar">
      <a className="logo" href="#" onClick={() => setPage('Explore')}>foodjett<span className="logo-mark"><Icon name="bolt" size={22}/></span></a>
      <div className="workspace-label">YOUR DAILY GOOD FOOD</div>
      <nav aria-label="Main navigation">{navigation.map(([label, icon]) => <button key={label} aria-label={label} aria-current={page === label ? 'page' : undefined} title={label} className={`nav-item ${page === label ? 'active' : ''}`} onClick={() => { setPage(label); setQuery(''); setCategory('All') }}><Icon name={icon}/><span className="nav-label">{label}</span>{label === 'Messages' && <span className="nav-dot"/>}{label === 'My orders' && ordered && <span className="nav-count">1</span>}</button>)}</nav>
      <div className="sidebar-bottom"><div className="little-promo"><span className="promo-icon"><Icon name="bike" size={30}/></span><strong>Good food. Great perks.</strong><p>Your next favorite meal comes with a little extra.</p><button onClick={() => setModal('offers')}>Explore offers <Icon name="arrow" size={16}/></button></div><button className="help-button" onClick={() => setModal('help')}><Icon name="help"/>Help & support<Icon name="arrow" size={16}/></button><button className="profile" onClick={() => setPage('My account')}><span className="avatar">JP</span><span><strong>Hey, JP</strong><small>Let’s eat something good</small></span><Icon name="down" size={16}/></button></div>
    </aside>
    <div className="main-shell">
      <header><button className="delivery-address" onClick={() => setModal('address')}><span className="pin-box"><Icon name="pin"/></span><span className="address-copy"><small>DELIVER TO</small><strong><span className="address-text">{address}</span><Icon name="down" size={15}/></strong></span></button><div className="header-actions"><span className="delivery-status"><i/> Delivering good food, every day</span><button className="cart-button" aria-label={`Your cart, ${cart.length} items`} onClick={() => setModal('cart')}><Icon name="bag" size={18}/><span className="cart-label">Your cart</span><span className="cart-count">{cart.length}</span></button></div></header>
      <main>
        {page === 'Explore' || page === 'Favorites' ? <>
          <div className="welcome-row"><div><div className="eyebrow"><span/> A LITTLE HUNGRY? YOU’RE IN THE RIGHT PLACE.</div><h1>{page === 'Favorites' ? 'Your tried-and-loved favorites.' : <>Good food. <span>Good mood.</span></>}</h1><p>{page === 'Favorites' ? 'The places you love, all in one spot.' : 'Your neighborhood favorites, delivered fresh to your door.'}</p></div><div className="local-note"><Icon name="pin" size={15}/>Made for your neighborhood</div></div>
          {page === 'Explore' && <section className="hero"><div className="hero-copy"><span className="hero-badge"><Icon name="bolt" size={14}/> BIG FLAVOR. SMALL DELIVERY FEE.</span><h2>Your cravings,<br/>on the house.</h2><p>A little treat from us. Enjoy free delivery<br className="desktop-break"/> on your first order.</p><button onClick={() => { setFreeDelivery(true); document.getElementById('restaurants')?.scrollIntoView({ behavior: 'smooth' }) }}>Find your first bite <Icon name="arrow" size={18}/></button><small>Use code <b>HEYFOODJETT</b> · Min. order ₱299</small></div><div className="hero-photo"><img src={burger} alt="Fresh cheeseburger with golden fries"/><div className="photo-shade"/></div><div className="hero-sticker"><Icon name="bike" size={25}/><strong>Fresh to<br/>your doorstep.</strong><span>That’s the Foodjett way.</span></div><span className="hero-index">01 <span>/ 03</span></span></section>}
          <section className="category-section"><div className="section-title"><h2>What’s your craving?</h2><span>Something for every mood</span></div><div className="categories">{categories.map(([label, kind]) => <button className={`category ${category === label ? 'selected' : ''}`} key={label} onClick={() => setCategory(label)}><span><FoodIcon kind={kind}/></span>{label}</button>)}</div></section>
          <section id="restaurants"><div className="restaurant-heading"><div><h2>{page === 'Favorites' ? 'Saved restaurants' : 'Good eats near you'} <span className="open-badge"><i/> OPEN NOW</span></h2><p>{filtered.length} places ready to make your day a little tastier</p></div><div className="restaurant-controls"><label className="search"><Icon name="search" size={17}/><input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search restaurants or dishes" aria-label="Search restaurants"/></label><button className={`filter-button ${freeDelivery ? 'enabled' : ''}`} onClick={() => setModal('filters')}><Icon name="tune" size={17}/>Filters{freeDelivery && <i/>}</button></div></div>
          <div className="quick-filters"><button className={!freeDelivery ? 'current' : ''} onClick={() => setFreeDelivery(false)}>For you</button><button className={freeDelivery ? 'current' : ''} onClick={() => setFreeDelivery(!freeDelivery)}><Icon name="bike" size={15}/>Free delivery</button><button onClick={() => setSort(sort === 'Top rated' ? 'Recommended' : 'Top rated')} className={sort === 'Top rated' ? 'current' : ''}><Icon name="star" size={14}/>Top rated</button><button onClick={() => setSort(sort === 'Fastest delivery' ? 'Recommended' : 'Fastest delivery')} className={sort === 'Fastest delivery' ? 'current' : ''}><Icon name="clock" size={14}/>Under 30 min</button><label className="sort-label">Sort by: <select value={sort} onChange={e => setSort(e.target.value)}><option>Recommended</option><option>Top rated</option><option>Fastest delivery</option></select></label></div>
          <div className="restaurant-grid">{filtered.map(r => <article className="restaurant-card" key={r.id}><div className="card-photo" onClick={() => setSelected(r)}><img src={r.image} alt={r.name + ' food'}/><span className={`food-tag ${r.id === 2 ? 'discount' : ''}`}>{r.id === 1 && <Icon name="bolt" size={12}/>} {r.tag}</span><button aria-label={favorites.includes(r.id) ? `Remove ${r.name} from favorites` : `Save ${r.name}`} className={`favorite ${favorites.includes(r.id) ? 'saved' : ''}`} onClick={e => { e.stopPropagation(); save(r.id) }}><Icon name="heart" size={17}/></button></div><button className="card-body" onClick={() => setSelected(r)}><div className="card-title"><h3>{r.name}</h3><span><Icon name="star" size={13}/><b>{r.rating}</b><small>({r.reviews})</small></span></div><p>{r.description}</p><div className="card-meta"><span><Icon name="clock" size={14}/>{r.time} min</span><span className={r.fee === 'Free' ? 'free' : ''}><Icon name="bike" size={15}/>{r.fee === 'Free' ? 'Free delivery' : r.fee + ' delivery'}</span><span>₱₱</span></div></button></article>)}</div>
          {!filtered.length && <div className="empty"><Icon name="search" size={32}/><h3>No restaurants here yet</h3><p>Try another craving or save a place you love.</p><button onClick={() => {setCategory('All');setQuery('');setFreeDelivery(false)}}>Clear filters</button></div>}</section>
          <footer><span className="footer-brand">A little local love in every order.</span><span>Good food, powered by Foodjett.</span></footer>
        </> : <section className="secondary-page"><div className="eyebrow">YOUR FOODJETT</div><h1>{page}</h1><p>Everything you need, right here.</p>
          {page === 'My orders' && <div className="content-panel"><Icon name="bag" size={36}/><h2>{ordered ? 'Your food is on its way' : 'Your next great meal starts here'}</h2><p>{ordered ? 'Demo order #FJ-1024 · Preparing · Estimated arrival in 25–35 minutes' : 'You haven’t placed an order in this demo yet.'}</p><button className="primary-button" onClick={() => ordered ? setModal('tracking') : setPage('Explore')}>{ordered ? 'Track order' : 'Explore restaurants'}<Icon name="arrow" size={16}/></button></div>}
          {page === 'Messages' && <div className="content-panel"><Icon name="message" size={36}/><h2>Let’s keep in touch</h2><p>Restaurant and rider conversations will appear here when you place an order.</p><button className="primary-button" onClick={() => setModal('help')}>Contact support</button></div>}
          {page === 'My account' && <div className="account-panel"><span className="avatar large">JP</span><h2>JP’s account</h2><label>Full name<input defaultValue="JP Santos"/></label><label>Email address<input type="email" defaultValue="jp@example.com"/></label><label>Phone number<input defaultValue="+63 917 123 4567"/></label><button className="primary-button" onClick={() => {setNotice('Your profile has been saved.');setTimeout(() => setNotice(''), 3000)}}>Save changes</button><button className="text-button" onClick={() => setModal('address')}>Manage delivery address</button></div>}
        </section>}
      </main>
    </div>
    {notice && <div className="toast" role="status">{notice}</div>}
    {(modal || selected) && <div className="modal-backdrop" onClick={close}><section className="modal" role="dialog" aria-modal="true" aria-label={selected?.name || modal} onClick={e => e.stopPropagation()}><button className="modal-close" onClick={close} aria-label="Close dialog"><Icon name="close"/></button>
      {selected && <><img className="menu-photo" src={selected.image} alt={selected.name}/><div className="modal-content"><span className="eyebrow">FRESH FROM THE KITCHEN</span><h2>{selected.name}</h2><p>{selected.description} · {selected.time} min</p><div className="menu-item"><div><h3>House favorite</h3><p>A generous serving of our signature dish,<br/>made fresh just for you.</p><strong>₱{selected.price}</strong></div><button className="primary-button" onClick={() => {setCart([...cart, {name: selected.name + ' · House favorite', price: selected.price}]);close();setNotice('Added to your cart');setTimeout(() => setNotice(''), 2500)}}>Add to cart +</button></div></div></>}
      {!selected && <div className="modal-content"><h2>{({cart:'Your little bag of happiness',address:'Where should we deliver?',filters:'Find your kind of food',offers:'A little treat from Foodjett',help:'We’re here to help',tracking:'Follow your food'})[modal]}</h2>
      {modal === 'cart' && <>{!cart.length ? <p>Your cart is empty. Find something delicious to get started.</p> : <>{cart.map((item, i) => <div className="cart-line" key={i}><span>{item.name}</span><b>₱{item.price}</b><button aria-label="Remove item" onClick={() => setCart(cart.filter((_, n) => i !== n))}><Icon name="close" size={15}/></button></div>)}<div className="cart-total">Total <strong>₱{cart.reduce((sum, item) => sum + item.price, 0)}</strong></div><p className="demo-note">Interactive preview only. No payment will be taken.</p><button className="primary-button" onClick={() => {setOrdered(true);setCart([]);close();setPage('My orders')}}>Place demo order <Icon name="arrow" size={16}/></button></>}</>}
      {modal === 'address' && <form onSubmit={e => {e.preventDefault();close()}}><p>Good food starts with the right address.</p><label className="form-label">Delivery address<input required value={address} onChange={e => setAddress(e.target.value)}/></label><button className="primary-button" type="submit">Save address</button></form>}
      {modal === 'filters' && <><p>Make your next meal exactly what you’re craving.</p><label className="check-label"><input type="checkbox" checked={freeDelivery} onChange={e => setFreeDelivery(e.target.checked)}/>Free delivery only</label><button className="primary-button" onClick={close}>Show restaurants</button></>}
      {modal === 'offers' && <><div className="offer-code">HEYFOODJETT</div><p>Free delivery on your first order of ₱299 or more. Explore participating restaurants in this demo.</p><button className="primary-button" onClick={() => {setFreeDelivery(true);setPage('Explore');close()}}>Explore free delivery</button></>}
      {modal === 'help' && <><p>Questions about your order or account? Our team is here for you.</p><a className="primary-button" href="mailto:support@foodjett.com">Email support <Icon name="arrow" size={16}/></a><p className="demo-note">Demo contact. No live support chat is connected.</p></>}
      {modal === 'tracking' && <><p className="demo-note">Demo tracking · Estimated arrival 25–35 min</p><div className="tracking-step complete">1. Order confirmed</div><div className="tracking-step complete">2. Your kitchen is preparing your meal</div><div className="tracking-step">3. Rider picks up your order</div><div className="tracking-step">4. Good food at your doorstep</div></>}
      </div>}
    </section></div>}
  </div>
}
